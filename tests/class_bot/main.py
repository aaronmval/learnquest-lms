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
student_profiles = {}      # {user_id: {"history": [], "interests": [], "conversation": [], "active_module": None}}
module_cache = {}          # {module_id: {"title": str, "content": str, "summary": str, "chunks": []}}
chat_history = []          # Global chat (deprecated, use per-user)

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
        "max_tokens": 2000
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

def search_pdf_content(module_id: str, query: str, top_k: int = 4) -> str:
    """Search through PDF content for relevant chunks"""
    if module_id not in module_cache:
        return None
    
    module = module_cache[module_id]
    content = module.get("content", "")
    
    # Find relevant chunks containing query keywords
    query_words = set(query.lower().split())
    # Remove common stopwords for better matching
    stopwords = {'what', 'is', 'are', 'the', 'a', 'an', 'of', 'to', 'for', 'on', 'at', 'with', 'by', 'from', 'up', 'about', 'into', 'through', 'during', 'without', 'after', 'before', 'under', 'over'}
    query_words = query_words - stopwords
    
    if not query_words:
        query_words = set(query.lower().split())
    
    chunks = module.get("chunks", chunk_text(content))
    
    scored_chunks = []
    for chunk in chunks:
        chunk_lower = chunk.lower()
        chunk_words = set(chunk_lower.split())
        overlap = len(query_words & chunk_words)
        if overlap > 0:
            scored_chunks.append((overlap, chunk))
    
    scored_chunks.sort(reverse=True)
    top_chunks = [chunk for _, chunk in scored_chunks[:top_k]]
    
    if top_chunks:
        return "\n\n---\n\n".join(top_chunks)
    else:
        # If no matches, return the first few chunks
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

def generate_study_guide(lesson_text: str) -> str:
    """Generate a comprehensive study guide from the module"""
    
    clean_text = lesson_text[:4000]
    
    prompt = f"""
    You are an expert STEM educator. Create a comprehensive study guide based on the following content.
    
    Your study guide should include:
    1. **Main Topic Overview** - What is this about?
    2. **Key Definitions** - Important terms with simple explanations
    3. **Core Concepts** - The main ideas explained clearly
    4. **Important Formulas/Equations** (if applicable)
    5. **Study Tips** - How to remember and apply this content
    6. **Practice Questions** - 3-5 questions with answers (for self-testing)
    7. **Summary** - One paragraph wrap-up
    
    Format it in a clear, organized way that's easy to study from.
    
    Content:
    {clean_text}
    """
    
    try:
        response = call_routeway([
            {"role": "system", "content": "You are an expert STEM educator who creates comprehensive, organized study guides."},
            {"role": "user", "content": prompt}
        ], temperature=0.2)
        return response
    except Exception as e:
        return f"❌ Failed to generate study guide: {str(e)}"

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
    You are an expert STEM tutor. Answer the student's question based ONLY on the document content provided below.
    
    **Document Title:** {document_title}
    
    **Relevant content from the document:**
    {relevant_content}
    
    **Student's Question:** {question}
    
    Instructions:
    1. Answer the question using ONLY information from the document above
    2. If the document doesn't contain the answer, say "I don't see that specific information in the document"
    3. Be clear, concise, and student-friendly
    4. Use examples from the document when relevant
    5. If the question asks about the document itself (like "what is this document about"), summarize the key topics
    6. Format your answer in a clear, easy-to-read way
    
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

def generate_tutoring_response(student_message: str, user_id: str, module_id: str = None) -> str:
    """Generate a personalized tutoring response with conversation memory"""
    
    # Initialize user profile if needed
    if user_id not in student_profiles:
        student_profiles[user_id] = {"history": [], "interests": [], "conversation": [], "active_module": None}
    
    profile = student_profiles[user_id]
    
    # Track if we should use the PDF
    use_pdf = False
    active_module_id = profile.get("active_module")
    
    # If module_id is provided, use it and update active module
    if module_id and module_id in module_cache:
        use_pdf = True
        profile["active_module"] = module_id
        active_module_id = module_id
    elif active_module_id and active_module_id in module_cache:
        use_pdf = True
        module_id = active_module_id
    
    # Check if user wants to detach
    detach_keywords = ["detach", "forget pdf", "stop using pdf", "ignore document", "remove pdf", "detach document"]
    if any(kw in student_message.lower() for kw in detach_keywords):
        profile["active_module"] = None
        return "✅ **PDF detached!** I'll no longer reference the uploaded document. Feel free to ask me anything else! 🎉"
    
    # Get conversation history (last 10 messages)
    conversation_history = profile.get("conversation", [])
    recent_messages = conversation_history[-10:] if conversation_history else []
    
    # Build context from conversation history
    context_parts = []
    for msg in recent_messages:
        context_parts.append(f"{msg['role']}: {msg['content']}")
    context_str = "\n".join(context_parts) if context_parts else ""
    
    # Get student context
    student_context = ""
    history = profile.get("history", [])
    if history:
        recent_topics = ", ".join(set(history[-5:]))
        student_context = f"\nThis student has recently been learning about: {recent_topics}."
    
    # If we should use the PDF, use the document Q&A function
    if use_pdf and module_id and module_id in module_cache:
        # Check if the question is about the document itself
        doc_question_keywords = ["what is this document", "what is this about", "summarize this document", "what does this cover"]
        if any(kw in student_message.lower() for kw in doc_question_keywords):
            module = module_cache[module_id]
            return f"**Document:** {module.get('title', 'Untitled')}\n\n{module.get('summary', 'No summary available.')}"
        
        # Use the document Q&A function
        doc_response = answer_question_about_document(student_message, module_id)
        
        # Store in conversation history
        profile["conversation"].append({"role": "user", "content": student_message})
        profile["conversation"].append({"role": "assistant", "content": doc_response})
        if len(profile["conversation"]) > 20:
            profile["conversation"] = profile["conversation"][-20:]
        profile["history"].append(student_message[:100])
        if len(profile["history"]) > 20:
            profile["history"].pop(0)
        
        return doc_response
    
    # If no document or document is detached, use general knowledge
    prompt = f"""
    You are LearnQuest, a friendly and knowledgeable STEM tutor for high school students.
    
    Your role is to:
    1. Help students understand STEM concepts
    2. Provide clear, step-by-step explanations
    3. Use examples to make concepts relatable
    4. Encourage students and build confidence
    5. Suggest specific resources or topics to study
    
    Be conversational, supportive, and engaging. Use emojis occasionally.
    {student_context}
    
    Previous conversation:
    {context_str}
    
    Student message: {student_message}
    """
    
    try:
        response = call_routeway([
            {"role": "system", "content": "You are LearnQuest, a supportive and knowledgeable STEM tutor for high school students."},
            {"role": "user", "content": prompt}
        ], temperature=0.7)
        
        # Store in conversation history
        profile["conversation"].append({"role": "user", "content": student_message})
        profile["conversation"].append({"role": "assistant", "content": response})
        if len(profile["conversation"]) > 20:
            profile["conversation"] = profile["conversation"][-20:]
        profile["history"].append(student_message[:100])
        if len(profile["history"]) > 20:
            profile["history"].pop(0)
        
        return response
    except Exception as e:
        return f"❌ Chat error: {str(e)}"

def generate_personalized_advice(user_id: str) -> str:
    """Generate personalized learning advice based on student history"""
    
    if user_id not in student_profiles or not student_profiles[user_id].get("history"):
        return "I don't have enough information yet. Ask me some questions or upload a module, and I'll learn about your interests!"
    
    profile = student_profiles[user_id]
    history = profile.get("history", [])
    
    prompt = f"""
    Based on the student's learning history, provide personalized advice:
    
    **Student's recent topics:** {', '.join(history[-10:])}
    
    Provide:
    1. **Recommended Topics** - What they should explore next
    2. **Study Strategies** - How they can learn more effectively
    3. **Resources** - What types of materials would help them
    4. **Encouragement** - A motivational message
    
    Be specific, encouraging, and actionable.
    """
    
    try:
        response = call_routeway([
            {"role": "system", "content": "You are a personalized learning advisor who provides encouraging, actionable advice."},
            {"role": "user", "content": prompt}
        ], temperature=0.3)
        return response
    except Exception as e:
        return f"❌ Failed to generate advice: {str(e)}"

# --------- MAIN ENDPOINTS ---------

@app.post("/upload")
async def upload_module(
    module_file: UploadFile = File(...),
    user_id: str = Form("default")
):
    """Upload a learning module and generate summary"""
    
    lesson_text = extract_text_from_file(module_file)
    if not lesson_text:
        return {"error": "Could not extract text from file. Please upload a valid PDF, DOCX, or PPTX file."}
    
    summary = generate_module_summary(lesson_text)
    topic = module_file.filename.rsplit('.', 1)[0]
    
    module_id = f"{user_id}_{datetime.now().strftime('%Y%m%d%H%M%S')}"
    module_cache[module_id] = {
        "title": topic,
        "content": lesson_text,
        "summary": summary,
        "chunks": chunk_text(lesson_text),
        "uploaded_at": datetime.now().isoformat()
    }
    
    # Store in profile and set as active
    if user_id not in student_profiles:
        student_profiles[user_id] = {"history": [], "interests": [], "conversation": [], "active_module": None}
    student_profiles[user_id]["interests"].append(topic)
    student_profiles[user_id]["active_module"] = module_id
    
    return {
        "module_id": module_id,
        "topic": topic,
        "summary": summary,
        "message": "✅ Module uploaded and summarized successfully! I'll use this document to answer your questions. Type 'detach' to stop using it."
    }

@app.post("/summarize")
async def summarize_module(
    module_file: UploadFile = File(...),
    complexity: str = Form("intermediate")
):
    """Generate a summary of the module"""
    
    lesson_text = extract_text_from_file(module_file)
    if not lesson_text:
        return {"error": "Could not extract text from file."}
    
    summary = generate_module_summary(lesson_text, complexity)
    
    return {
        "summary": summary,
        "message": "✅ Summary generated successfully!"
    }

@app.post("/study-guide")
async def create_study_guide(
    module_file: UploadFile = File(...)
):
    """Generate a comprehensive study guide from the module"""
    
    lesson_text = extract_text_from_file(module_file)
    if not lesson_text:
        return {"error": "Could not extract text from file."}
    
    guide = generate_study_guide(lesson_text)
    
    return {
        "study_guide": guide,
        "message": "✅ Study guide created successfully!"
    }

@app.post("/ask-pdf")
async def ask_pdf(
    module_id: str = Form(...),
    question: str = Form(...),
    user_id: str = Form("default")
):
    """Ask a specific question about an uploaded PDF"""
    
    if module_id not in module_cache:
        return {"error": "Module not found. Please upload the document first."}
    
    response = answer_question_about_document(question, module_id)
    
    return {
        "question": question,
        "response": response,
        "module_id": module_id,
        "message": "✅ Question answered using the document!"
    }

@app.post("/detach-pdf")
async def detach_pdf(
    user_id: str = Form("default")
):
    """Detach the current PDF so the AI stops referencing it"""
    if user_id in student_profiles:
        student_profiles[user_id]["active_module"] = None
        return {"message": "✅ PDF detached! I'll no longer reference the uploaded document.", "status": "detached"}
    return {"message": "No active PDF found to detach.", "status": "none"}

@app.post("/chat")
async def chat(
    user_id: str = Form("default"),
    chat_message: str = Form(None),
    message: str = Form(None)
):
    """Chat with the AI tutor for help and guidance"""
    
    user_message = chat_message or message
    
    if not user_message:
        return {"error": "No message provided"}
    
    response = generate_tutoring_response(user_message, user_id)
    
    return {"response": response}

@app.post("/advice")
async def get_advice(
    user_id: str = Form("default")
):
    """Get personalized learning advice"""
    
    advice = generate_personalized_advice(user_id)
    
    return {
        "advice": advice,
        "message": "📚 Here's your personalized learning advice!"
    }

@app.get("/profile/{user_id}")
async def get_profile(user_id: str):
    """Get student learning profile"""
    if user_id not in student_profiles:
        return {"message": "No data found for this user. Start learning by asking questions or uploading materials!"}
    
    profile = student_profiles[user_id]
    
    return {
        "profile": profile,
        "total_questions": len(profile.get("history", [])),
        "interests": profile.get("interests", []),
        "conversation_length": len(profile.get("conversation", [])),
        "active_module": profile.get("active_module"),
        "message": "📊 Here's your learning profile!"
    }

@app.post("/clear-chat")
async def clear_chat(user_id: str = Form("default")):
    """Clear chat history for a specific user"""
    if user_id in student_profiles:
        student_profiles[user_id]["conversation"] = []
        return {"message": f"Chat history cleared for {user_id}! 🧹"}
    return {"message": "No profile found for this user."}

@app.post("/clear-profile/{user_id}")
async def clear_profile(user_id: str):
    """Clear student profile data"""
    if user_id in student_profiles:
        del student_profiles[user_id]
    return {"message": f"Profile for {user_id} cleared!"}

@app.get("/")
async def health_check():
    return {
        "status": "✅ LearnQuest API is running!",
        "model": MODEL,
        "features": [
            "📝 Module Summarization",
            "📚 Study Guide Generation",
            "📄 Semantic PDF Search & Q&A",
            "💬 Conversation Memory",
            "💬 Tutoring Chat with Llama-3.3-70B",
            "🎯 Personalized Learning Advice",
            "🔗 PDF Detach Feature"
        ]
    }

@app.post("/module")
async def module_action(
    module_file: UploadFile = None,
    action: str = Form(None),
    question: str = Form(None),
    chat_message: str = Form(None),
    user_id: str = Form("default")
):
    """Combined endpoint for module actions and chat"""
    
    # Handle chat messages (no file)
    if not module_file and chat_message:
        try:
            response = generate_tutoring_response(chat_message, user_id)
            return {"result": response}
        except Exception as e:
            return {"error": f"Chat error: {str(e)}"}

    # Handle file-based actions
    if not module_file:
        return {"error": "Please upload a file or send a message."}
    
    lesson_text = extract_text_from_file(module_file)
    if not lesson_text:
        return {"error": "Could not extract text from file."}

    # Build response based on action
    if action == "summarize":
        result_text = generate_module_summary(lesson_text)
    elif action == "study-guide":
        result_text = generate_study_guide(lesson_text)
    elif action == "explain" and question:
        # This is the key fix - use the document Q&A function
        # Store the document first
        topic = module_file.filename.rsplit('.', 1)[0]
        module_id = f"{user_id}_{datetime.now().strftime('%Y%m%d%H%M%S')}"
        module_cache[module_id] = {
            "title": topic,
            "content": lesson_text,
            "summary": generate_module_summary(lesson_text),
            "chunks": chunk_text(lesson_text),
            "uploaded_at": datetime.now().isoformat()
        }
        if user_id in student_profiles:
            student_profiles[user_id]["active_module"] = module_id
        
        result_text = answer_question_about_document(question, module_id)
    else:
        # Default: summarize
        result_text = generate_module_summary(lesson_text)
    
    return {"result": result_text}