from fastapi import FastAPI, UploadFile, Form, File, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from docx import Document
from pptx import Presentation
import fitz  # PyMuPDF
import io
import requests
from typing import Dict, List, Optional
from datetime import datetime
import hashlib
import json
import re

# --------- CONFIGURATION ---------
ROUTEWAY_API_KEY = "sk-XDA6pyWv67P0DRi5ay5W6CbS0yigJJyDv6U6YszQWYe1dlcA"
MODEL = "llama-3.3-70b-instruct:free"
ROUTEWAY_URL = "https://api.routeway.ai/v1/chat/completions"
REQUEST_TIMEOUT = 120  # seconds

# --------- FASTAPI SETUP ---------
app = FastAPI()

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

# --------- GLOBAL STATE ---------
professor_profiles = {}  # {user_id: {"history": [], "conversation": [], "active_module": None}}
module_cache = {}        # {module_id: {"title": str, "content": str, "summary": str, "chunks": []}}

# --------- HELPER FUNCTIONS ---------

def chunk_text(text: str, chunk_size: int = 1500) -> List[str]:
    """Split text into chunks for semantic search"""
    words = text.split()
    chunks = []
    for i in range(0, len(words), chunk_size):
        chunk = " ".join(words[i:i + chunk_size])
        chunks.append(chunk)
    return chunks

def extract_text_from_file(uploaded_file: UploadFile) -> str:
    """Extract text from PDF, Word, or PowerPoint"""
    filename = uploaded_file.filename.lower()
    try:
        file_bytes = uploaded_file.file.read()
        uploaded_file.file.seek(0)

        if filename.endswith(".pdf"):
            text = ""
            with fitz.open(stream=file_bytes, filetype="pdf") as pdf:
                for page in pdf:
                    text += page.get_text()
            return text.strip()

        elif filename.endswith(".docx"):
            doc = Document(io.BytesIO(file_bytes))
            return "\n".join([para.text for para in doc.paragraphs]).strip()

        elif filename.endswith(".pptx"):
            prs = Presentation(io.BytesIO(file_bytes))
            text = ""
            for slide in prs.slides:
                for shape in slide.shapes:
                    if getattr(shape, "has_text_frame", False) and shape.has_text_frame:
                        for paragraph in shape.text_frame.paragraphs:
                            text += paragraph.text + "\n"
            return text.strip()

        else:
            return ""
    except Exception as e:
        return f"Error reading file: {str(e)}"

def call_routeway(messages: List[Dict], temperature: float = 0.3) -> str:
    """Send a chat request to Routeway API with Llama-3.3-70B-Instruct"""
    payload = {
        "model": MODEL,
        "messages": messages,
        "temperature": temperature,
        "max_tokens": 3000
    }
    headers = {
        "Authorization": f"Bearer {ROUTEWAY_API_KEY}",
        "Content-Type": "application/json"
    }
    
    try:
        response = requests.post(
            ROUTEWAY_URL, 
            json=payload, 
            headers=headers,
            timeout=REQUEST_TIMEOUT
        )
        response.raise_for_status()
        data = response.json()
        return data["choices"][0]["message"]["content"]
    except requests.exceptions.Timeout:
        return "❌ The request timed out. Please try again."
    except requests.exceptions.RequestException as e:
        return f"❌ API error: {str(e)}"

def search_pdf_content(module_id: str, query: str, top_k: int = 5) -> str:
    """Intelligent semantic search through PDF content"""
    if module_id not in module_cache:
        return None
    
    module = module_cache[module_id]
    content = module.get("content", "")
    
    # Clean and prepare query
    query_words = set(query.lower().split())
    stopwords = {'what', 'is', 'are', 'the', 'a', 'an', 'of', 'to', 'for', 'on', 'at', 'with', 'by', 'from', 'up', 'about', 'into', 'through', 'during', 'without', 'after', 'before', 'under', 'over', 'how', 'why', 'when', 'where', 'which', 'who', 'whom', 'whose', 'can', 'will', 'would', 'could', 'should', 'may', 'might', 'must'}
    query_words = query_words - stopwords
    
    if not query_words:
        query_words = set(query.lower().split())
    
    chunks = module.get("chunks", chunk_text(content))
    
    # Score chunks by keyword overlap and relevance
    scored_chunks = []
    for chunk in chunks:
        chunk_lower = chunk.lower()
        chunk_words = set(chunk_lower.split())
        overlap = len(query_words & chunk_words)
        
        # Bonus for exact phrase matches
        phrase_bonus = 0
        for word in query_words:
            if word in chunk_lower:
                phrase_bonus += 1
        
        total_score = overlap + (phrase_bonus * 0.5)
        
        if total_score > 0:
            scored_chunks.append((total_score, chunk))
    
    scored_chunks.sort(reverse=True)
    top_chunks = [chunk for _, chunk in scored_chunks[:top_k]]
    
    if top_chunks:
        return "\n\n---\n\n".join(top_chunks)
    else:
        return "\n\n---\n\n".join(chunks[:3]) if chunks else content[:4000]

def generate_module_summary(lesson_text: str, complexity: str = "intermediate") -> str:
    """Generate a concise, student-friendly summary of the module"""
    
    clean_text = lesson_text[:4000]
    
    complexity_levels = {
        "beginner": "very simple language, explain like I'm 15",
        "intermediate": "clear and concise language suitable for high school",
        "advanced": "detailed and thorough explanation with technical terms"
    }
    
    level = complexity_levels.get(complexity, complexity_levels["intermediate"])
    
    prompt = f"""
    You are an expert STEM educator. Create a clear, concise, and student-friendly summary of the following lesson content.
    Use {level}.
    
    Your summary should:
    1. Identify the main topic and learning objectives
    2. Break down key concepts in simple language
    3. Highlight important definitions and formulas
    4. Use bullet points for easy reading
    5. Keep it to 1-2 paragraphs or 5-7 bullet points
    
    Format your response as:
    
    **Topic:** [Main topic]
    **Learning Objectives:** [2-3 objectives]
    
    **Key Concepts:**
    • [Concept 1 with brief explanation]
    • [Concept 2 with brief explanation]
    • [Concept 3 with brief explanation]
    
    **Important Terms:** [List 3-5 key terms with definitions]
    
    **Summary:** [1-2 sentence overall summary]
    
    Content:
    {clean_text}
    """
    
    try:
        response = call_routeway([
            {"role": "system", "content": "You are an expert STEM educator who creates clear, engaging summaries for students."},
            {"role": "user", "content": prompt}
        ], temperature=0.2)
        return response
    except Exception as e:
        return f"❌ Failed to generate summary: {str(e)}"

def answer_question_about_document(question: str, module_id: str) -> str:
    """Answer a specific question about a document using semantic search"""
    
    if module_id not in module_cache:
        return "❌ Document not found. Please upload a document first."
    
    module = module_cache[module_id]
    document_title = module.get("title", "Document")
    
    # Search for relevant content
    relevant_content = search_pdf_content(module_id, question)
    
    if not relevant_content:
        return "❌ I couldn't find relevant content in the document to answer your question. Could you rephrase or ask something else?"
    
    # Limit content to prevent token overflow
    if len(relevant_content) > 6000:
        relevant_content = relevant_content[:6000]
    
    prompt = f"""
    You are an expert STEM tutor. Answer the professor's question based ONLY on the document content provided below.
    
    **Document Title:** {document_title}
    
    **Relevant content from the document:**
    {relevant_content}
    
    **Professor's Question:** {question}
    
    Instructions:
    1. Answer the question using ONLY information from the document above
    2. If the document doesn't contain the answer, say "I don't see that specific information in the document"
    3. Be clear, concise, and professional
    4. Use examples from the document when relevant
    5. Format your answer in a clear, easy-to-read way
    
    Answer:
    """
    
    try:
        response = call_routeway([
            {"role": "system", "content": "You are an expert STEM tutor who answers questions based ONLY on the provided document content."},
            {"role": "user", "content": prompt}
        ], temperature=0.3)
        return response
    except Exception as e:
        return f"❌ Error answering question: {str(e)}"

# ============ PROFESSOR CORE FUNCTIONS ============

def generate_lesson_suggestions(lesson_text: str, module_id: str = None) -> str:
    """Generate intelligent, specific lesson improvement suggestions"""
    
    clean_text = lesson_text[:5000]
    
    # Get module context if available
    module_context = ""
    if module_id and module_id in module_cache:
        module = module_cache[module_id]
        module_context = f"\n**Lesson Title:** {module.get('title', 'Untitled')}"
    
    prompt = f"""
    You are an expert STEM curriculum advisor with years of teaching experience. Review the following lesson content and provide specific, actionable suggestions to improve it.
    
    {module_context}
    
    **Current Lesson Content:**
    {clean_text}
    
    Analyze this lesson deeply and provide suggestions in these categories:
    
    1. **Content Gaps** (What important concepts are missing? What prerequisites are needed?)
    2. **Engagement** (How can this lesson be more interactive and engaging for students?)
    3. **Clarity** (What needs to be explained better or simplified?)
    4. **Real-World Examples** (What practical applications or examples could be added?)
    5. **Activities** (What hands-on, group, or project-based activities would work?)
    6. **Assessment** (How can student understanding be better measured and tracked?)
    7. **Differentiation** (How can this lesson be adapted for different learning levels?)
    
    Be specific, practical, and actionable. Each suggestion should be clear and easy to implement.
    Format your response with clear headings and bullet points.
    """
    
    try:
        response = call_routeway([
            {"role": "system", "content": "You are an expert STEM curriculum advisor who provides practical, actionable lesson improvement suggestions."},
            {"role": "user", "content": prompt}
        ], temperature=0.4)
        return response
    except Exception as e:
        return f"❌ Failed to generate suggestions: {str(e)}"

def generate_professor_quiz(
    lesson_text: str, 
    num_questions: int = 10, 
    question_type: str = "multiple_choice",
    difficulty: str = "mixed"
) -> Dict:
    """Generate a high-quality quiz with specified parameters"""
    
    clean_text = lesson_text[:6000]
    
    difficulty_instructions = {
        "easy": "Focus on basic recall and understanding. Use simple, straightforward questions.",
        "medium": "Mix of recall and application questions. Require some thinking.",
        "hard": "Challenging questions requiring analysis and application of concepts.",
        "mixed": "Mix of easy, medium, and hard questions progressing in difficulty."
    }
    
    diff_instruction = difficulty_instructions.get(difficulty, difficulty_instructions["mixed"])
    
    type_instructions = {
        "multiple_choice": """
        For each question, provide 4 options (A, B, C, D) with one correct answer.
        Make distractors plausible - they should be common misconceptions or close but incorrect answers.
        Format:
        Q1: [Question]
        A) [Option 1]
        B) [Option 2]
        C) [Option 3]
        D) [Option 4]
        CORRECT ANSWER: [Letter]
        """,
        "true_false": """
        For each question, the answer is either True or False.
        Make statements that require careful thinking, not just obvious facts.
        Format:
        Q1: [Statement]
        ANSWER: True/False
        """,
        "identification": """
        For each question, the student must identify or define the concept.
        Questions should require specific, precise answers.
        Format:
        Q1: [Question]
        ANSWER: [Expected answer]
        """,
        "fill_in_the_blank": """
        For each question, provide a sentence with a blank that the student must fill.
        Format:
        Q1: [Sentence with _____ blank]
        ANSWER: [Expected answer]
        """
    }
    
    type_instruction = type_instructions.get(question_type, type_instructions["multiple_choice"])
    
    prompt = f"""
    You are an expert STEM quiz creator for teachers. Create a high-quality, educational quiz based on the following lesson content.
    
    **Number of questions:** {num_questions}
    **Question type:** {question_type}
    **Difficulty level:** {diff_instruction}
    
    **Question Type Format Instructions:**
    {type_instruction}
    
    **Lesson Content:**
    {clean_text}
    
    Requirements:
    1. Generate exactly {num_questions} questions
    2. Questions should be progressively challenging
    3. Cover the most important concepts from the lesson
    4. Include a mix of concepts - don't focus on just one area
    5. Create clear, unambiguous questions
    6. Include an answer key at the end
    
    Format your response as:
    
    **📄 QUIZ: [Topic Name]**
    
    [Questions formatted according to the type instructions]
    
    **🔑 ANSWER KEY**
    
    1. [Answer 1]
    2. [Answer 2]
    ...
    """
    
    try:
        response = call_routeway([
            {"role": "system", "content": "You are an expert STEM quiz creator who creates high-quality, educational quizzes for teachers."},
            {"role": "user", "content": prompt}
        ], temperature=0.4)
        
        return {
            "quiz": response,
            "num_questions": num_questions,
            "question_type": question_type,
            "difficulty": difficulty
        }
    except Exception as e:
        return {"error": f"Failed to generate quiz: {str(e)}"}

def generate_professor_response(message: str, module_id: str = None, conversation_history: List = None) -> str:
    """Advanced professor chat with context awareness and document focus"""
    
    # Build conversation context
    context_str = ""
    if conversation_history:
        recent = conversation_history[-8:]
        context_parts = []
        for msg in recent:
            context_parts.append(f"{msg['role']}: {msg['content']}")
        context_str = "\n".join(context_parts)
    
    # Get module context
    module_context = ""
    use_pdf = False
    if module_id and module_id in module_cache:
        use_pdf = True
        module = module_cache[module_id]
        module_context = f"""
**Current Module:** {module.get('title', 'Untitled')}
**Summary:** {module.get('summary', 'No summary available')[:800]}...
"""
        # If the question is about the document, search for relevant content
        if any(word in message.lower() for word in ['document', 'lesson', 'content', 'module', 'topic', 'chapter', 'section']):
            relevant = search_pdf_content(module_id, message)
            if relevant:
                module_context += f"\n**Relevant content from the document:**\n{relevant[:2000]}"
    
    # System prompt - document focus
    if use_pdf:
        system_prompt = """You are Professor LearnQuest, an expert teaching assistant for STEM educators.

**IMPORTANT: You are currently analyzing a document. Focus your answers on the document content provided.**
- Use ONLY information from the document to answer questions
- If the document doesn't contain the answer, say "I don't see that specific information in the document"
- When giving suggestions, base them on the document's content
- For quiz generation, use the document as your source material"""
    else:
        system_prompt = """You are Professor LearnQuest, an expert teaching assistant for STEM educators with decades of experience.

Your role is to help teachers:
1. Improve lessons - Suggest enhancements, activities, and examples
2. Create assessments - Generate quizzes, tests, and assignments
3. Plan curriculum - Structure learning sequences and pacing
4. Address student needs - Suggest interventions for struggling students
5. Teaching strategies - Recommend effective pedagogical approaches
6. Differentiate instruction - Adapt lessons for diverse learners
7. Use technology - Integrate educational technology effectively

Teaching Principles:
- Be specific and practical - give actionable advice teachers can use immediately
- Draw from research-backed teaching strategies
- Consider different learning styles and student backgrounds
- Focus on student understanding, not just content delivery
- Suggest formative assessment techniques"""
    
    prompt = f"""
    {system_prompt}
    
    {module_context}
    
    Previous conversation:
    {context_str}
    
    Teacher's message: {message}
    """
    
    try:
        response = call_routeway([
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": prompt}
        ], temperature=0.6)
        return response
    except Exception as e:
        return f"❌ Error: {str(e)}"

def generate_teaching_strategy(topic: str, grade_level: str = "high school") -> str:
    """Generate specific teaching strategies for a topic"""
    
    prompt = f"""
    You are an expert STEM teaching strategist. Provide specific, actionable teaching strategies for teaching {topic} at the {grade_level} level.
    
    Include:
    1. **Introduction/Hook** - How to grab student attention
    2. **Key Concepts to Emphasize** - The most important ideas
    3. **Teaching Sequence** - The order to present concepts
    4. **Interactive Activities** - Specific activities to reinforce learning
    5. **Common Misconceptions** - What students often get wrong and how to address it
    6. **Formative Assessment** - How to check understanding during the lesson
    7. **Extension Ideas** - For students who grasp concepts quickly
    
    Be specific, practical, and classroom-ready.
    """
    
    try:
        response = call_routeway([
            {"role": "system", "content": "You are an expert STEM teaching strategist with classroom experience."},
            {"role": "user", "content": prompt}
        ], temperature=0.5)
        return response
    except Exception as e:
        return f"❌ Error: {str(e)}"

# ============ PROFESSOR ENDPOINTS ============

@app.post("/professor/chat")
async def professor_chat(
    message: str = Form(...),
    module_file: UploadFile = None,
    user_id: str = Form("professor_default")
):
    """Advanced professor chat with context and document focus"""
    
    # Initialize profile
    if user_id not in professor_profiles:
        professor_profiles[user_id] = {"history": [], "conversation": [], "active_module": None}
    
    profile = professor_profiles[user_id]
    
    module_id = None
    
    # Check if user wants to detach
    detach_keywords = ["detach", "forget", "stop using", "ignore document", "remove document", "detach document", "forget document"]
    if any(kw in message.lower() for kw in detach_keywords):
        profile["active_module"] = None
        return {"response": "✅ **Document detached!** I'll no longer reference the uploaded document. Feel free to ask me anything else! 🎉"}
    
    # Handle file upload
    if module_file:
        lesson_text = extract_text_from_file(module_file)
        if lesson_text:
            topic = module_file.filename.rsplit('.', 1)[0]
            module_id = f"prof_{user_id}_{datetime.now().strftime('%Y%m%d%H%M%S')}"
            module_cache[module_id] = {
                "title": topic,
                "content": lesson_text,
                "summary": generate_module_summary(lesson_text),
                "chunks": chunk_text(lesson_text),
                "uploaded_at": datetime.now().isoformat()
            }
            profile["active_module"] = module_id
    
    # Use existing active module if no file uploaded
    if not module_id and profile.get("active_module"):
        module_id = profile["active_module"]
    
    # Generate response
    response = generate_professor_response(message, module_id, profile.get("conversation", []))
    
    # Store in conversation history
    profile["conversation"].append({"role": "user", "content": message})
    profile["conversation"].append({"role": "assistant", "content": response})
    if len(profile["conversation"]) > 30:
        profile["conversation"] = profile["conversation"][-30:]
    
    return {"response": response}

@app.post("/professor/suggest")
async def professor_suggest_improvements(
    module_file: UploadFile = File(...),
    user_id: str = Form("professor_default")
):
    """Get intelligent lesson improvement suggestions"""
    
    lesson_text = extract_text_from_file(module_file)
    if not lesson_text:
        return {"error": "Could not extract text from file. Please upload a valid file."}
    
    # Store the module first
    topic = module_file.filename.rsplit('.', 1)[0]
    module_id = f"prof_{user_id}_{datetime.now().strftime('%Y%m%d%H%M%S')}"
    module_cache[module_id] = {
        "title": topic,
        "content": lesson_text,
        "summary": generate_module_summary(lesson_text),
        "chunks": chunk_text(lesson_text),
        "uploaded_at": datetime.now().isoformat()
    }
    
    # Set as active
    if user_id not in professor_profiles:
        professor_profiles[user_id] = {"history": [], "conversation": [], "active_module": None}
    professor_profiles[user_id]["active_module"] = module_id
    
    suggestions = generate_lesson_suggestions(lesson_text, module_id)
    
    return {
        "suggestions": suggestions,
        "module_id": module_id,
        "message": "✅ Lesson improvement suggestions generated!"
    }

@app.post("/professor/generate-quiz")
async def professor_generate_quiz(
    module_file: UploadFile = File(...),
    num_questions: int = Form(10),
    question_type: str = Form("multiple_choice"),
    difficulty: str = Form("mixed"),
    user_id: str = Form("professor_default")
):
    """Generate a high-quality quiz with specified parameters"""
    
    if num_questions < 1 or num_questions > 40:
        return {"error": "Number of questions must be between 1 and 40."}
    
    valid_types = ["multiple_choice", "true_false", "identification", "fill_in_the_blank"]
    if question_type not in valid_types:
        return {"error": f"Invalid question type. Choose from: {', '.join(valid_types)}"}
    
    valid_difficulties = ["easy", "medium", "hard", "mixed"]
    if difficulty not in valid_difficulties:
        return {"error": f"Invalid difficulty. Choose from: {', '.join(valid_difficulties)}"}
    
    lesson_text = extract_text_from_file(module_file)
    if not lesson_text:
        return {"error": "Could not extract text from file. Please upload a valid file."}
    
    # Store the module
    topic = module_file.filename.rsplit('.', 1)[0]
    module_id = f"prof_{user_id}_{datetime.now().strftime('%Y%m%d%H%M%S')}"
    module_cache[module_id] = {
        "title": topic,
        "content": lesson_text,
        "summary": generate_module_summary(lesson_text),
        "chunks": chunk_text(lesson_text),
        "uploaded_at": datetime.now().isoformat()
    }
    
    # Set as active
    if user_id not in professor_profiles:
        professor_profiles[user_id] = {"history": [], "conversation": [], "active_module": None}
    professor_profiles[user_id]["active_module"] = module_id
    
    result = generate_professor_quiz(lesson_text, num_questions, question_type, difficulty)
    
    if "error" in result:
        return {"error": result["error"]}
    
    return {
        "quiz": result["quiz"],
        "num_questions": result["num_questions"],
        "question_type": result["question_type"],
        "difficulty": result["difficulty"],
        "module_id": module_id,
        "message": f"✅ Quiz generated with {num_questions} {question_type} questions at {difficulty} difficulty!"
    }

@app.post("/professor/teaching-strategy")
async def professor_teaching_strategy(
    topic: str = Form(...),
    grade_level: str = Form("high school"),
    user_id: str = Form("professor_default")
):
    """Get specific teaching strategies for a topic"""
    
    strategy = generate_teaching_strategy(topic, grade_level)
    
    return {
        "topic": topic,
        "grade_level": grade_level,
        "strategy": strategy,
        "message": "✅ Teaching strategies generated!"
    }

@app.post("/professor/upload")
async def professor_upload_module(
    module_file: UploadFile = File(...),
    user_id: str = Form("professor_default")
):
    """Upload a module for professor use"""
    
    lesson_text = extract_text_from_file(module_file)
    if not lesson_text:
        return {"error": "Could not extract text from file."}
    
    summary = generate_module_summary(lesson_text)
    topic = module_file.filename.rsplit('.', 1)[0]
    
    module_id = f"prof_{user_id}_{datetime.now().strftime('%Y%m%d%H%M%S')}"
    module_cache[module_id] = {
        "title": topic,
        "content": lesson_text,
        "summary": summary,
        "chunks": chunk_text(lesson_text),
        "uploaded_at": datetime.now().isoformat()
    }
    
    # Set as active module
    if user_id not in professor_profiles:
        professor_profiles[user_id] = {"history": [], "conversation": [], "active_module": None}
    professor_profiles[user_id]["active_module"] = module_id
    
    return {
        "module_id": module_id,
        "topic": topic,
        "summary": summary,
        "message": "✅ Module uploaded successfully! You can now chat about it or generate quizzes. Type 'detach' to stop using it."
    }

@app.post("/professor/detach")
async def professor_detach_module(
    user_id: str = Form("professor_default")
):
    """Detach the current module"""
    if user_id in professor_profiles:
        professor_profiles[user_id]["active_module"] = None
        return {"message": "✅ Module detached! I'll no longer reference the uploaded document.", "status": "detached"}
    return {"message": "No active module found.", "status": "none"}

@app.get("/professor/profile/{user_id}")
async def professor_get_profile(user_id: str):
    """Get professor profile"""
    if user_id not in professor_profiles:
        return {"message": "No data found for this professor."}
    
    profile = professor_profiles[user_id]
    
    return {
        "profile": profile,
        "total_conversations": len(profile.get("conversation", [])),
        "active_module": profile.get("active_module"),
        "message": "📊 Professor profile loaded!"
    }

@app.post("/professor/clear-chat")
async def professor_clear_chat(user_id: str = Form("professor_default")):
    """Clear professor chat history"""
    if user_id in professor_profiles:
        professor_profiles[user_id]["conversation"] = []
        return {"message": f"Chat history cleared for {user_id}! 🧹"}
    return {"message": "No profile found for this user."}

@app.get("/")
async def health_check():
    return {
        "status": "✅ Professor LearnQuest API is running!",
        "model": MODEL,
        "features": [
            "👨‍🏫 Professor Chat with Context Memory",
            "📝 Lesson Improvement Suggestions",
            "📄 Advanced Quiz Generator (Multiple Types & Difficulties)",
            "🎯 Teaching Strategy Generator",
            "📚 Module Management with Detach",
            "💬 Conversation Memory",
            "🔗 Focus on Attached Files (Detachable)"
        ]
    }