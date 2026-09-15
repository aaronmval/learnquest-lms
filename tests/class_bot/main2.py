from fastapi import FastAPI, UploadFile, Form, File
from fastapi.middleware.cors import CORSMiddleware
from docx import Document
from pptx import Presentation
import fitz  # PyMuPDF
import io
import requests
import re
import random
import json
from typing import Dict, List, Optional

# --------- CONFIGURATION ---------
OPENROUTER_API_KEY = "sk-XDA6pyWv67P0DRi5ay5W6CbS0yigJJyDv6U6YszQWYe1dlcA"
MODEL = "llama-3.3-70b-instruct:free"
OPENROUTER_URL = "https://api.routeway.ai/v1/chat/completions"

# --------- FASTAPI SETUP ---------
app = FastAPI()

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

# --------- GLOBAL STATE ---------
user_scripts = {}  # Store user-defined scripts: {"am i smart": "yes"}
current_topic = None  # Track current document topic
chat_history = []  # Keep recent chat history for context
active_quizzes = {}  # Store active quizzes: {user_id: {questions: [], answers: [], phase: int}}

# --------- INTERACTIVE RESPONSES ---------
def handle_special_messages(message: str) -> str:
    """Handle greetings, compliments, curses, jokes, and special commands"""
    message_lower = message.lower().strip()
    
    # Check user scripts first (highest priority)
    for trigger, response in user_scripts.items():
        if trigger.lower() in message_lower:
            return response
    
    # Topic switching
    if any(phrase in message_lower for phrase in ['drop topic', 'change topic', 'new topic', 'forget document', 'stop talking about this']):
        global current_topic
        current_topic = None
        return "👍 Got it! I've dropped the current topic. What would you like to talk about now?"
    
    # Joke requests
    if any(phrase in message_lower for phrase in ['tell me a joke', 'make me laugh', 'joke', 'funny']):
        jokes = [
            "Why did the AI cross the road? To get to the other... wait, let me recalculate that. 🤔",
            "Why do programmers prefer dark mode? Because light attracts bugs! 🐛",
            "What's an AI's favorite snack? Micro-chips! 😂",
            "Why was the math book sad? It had too many problems! 📚",
            "I told my computer I needed a break... now it won't stop sending me vacation ads! 🏖️"
        ]
        return f"😂 {random.choice(jokes)}"
    
    # Mood-based responses
    if any(word in message_lower for word in ['bored', 'tired', 'sleepy']):
        return "😴 Feeling bored? Let's spice things up! Want to hear a joke or try a fun quiz?"
    
    if any(word in message_lower for word in ['excited', 'happy', 'awesome', 'great']):
        return "🎉 That's awesome! Your energy is contagious! What shall we learn today?"
    
    if any(word in message_lower for word in ['sad', 'upset', 'frustrated']):
        return "🤗 I'm here for you! Learning can be tough sometimes. Want to take it slow or try something fun?"
    
    # Script setting
    if "when i say" in message_lower and "you say" in message_lower:
        # Extract script: "when i say X you say Y"
        match = re.search(r'when i say[\'"]?([^\'"]+)[\'"]? you say[\'"]?([^\'"]+)[\'"]?', message_lower)
        if match:
            trigger = match.group(1).strip()
            response = match.group(2).strip()
            user_scripts[trigger] = response
            return f"✅ Got it! From now on, when you say '{trigger}', I'll respond with '{response}'!"
    
    # Greetings
    greetings = ['hello', 'hi', 'hey', 'hola', 'greetings', 'good morning', 'good afternoon', 'good evening']
    if any(greet in message_lower for greet in greetings):
        return random.choice([
            "Hey there! 👋 Ready to study or just chat?",
            "Hello! 😊 What's on your mind today?",
            "Hi! 🎒 Got any study materials or shall we just talk?"
        ])
    
    # Compliments
    compliments = ['thanks', 'thank you', 'good job', 'well done', 'awesome', 'great', 'amazing', 'you\'re smart']
    if any(comp in message_lower for comp in compliments):
        return random.choice([
            "Aww, thanks! 😊 You're making me blush!",
            "Thank you! I'm here to make learning fun for you! 🎯",
            "I appreciate that! 😄 You're pretty awesome yourself!"
        ])
    
    # Farewells
    farewells = ['bye', 'goodbye', 'see you', 'farewell', 'cya']
    if any(farewell in message_lower for farewell in farewells):
        return random.choice([
            "Goodbye! 👋 Can't wait to chat again!",
            "See you later! 📚 Keep being curious!",
            "Bye! 😊 Remember, learning is a journey - enjoy the ride!"
        ])
    
    # Curses/Insults (with humor)
    curses = ['fuck you', 'shit', 'asshole', 'dumb', 'stupid', 'sucks', 'bullshit', 'bitch']
    if any(curse in message_lower for curse in curses):
        sassy_responses = [
            "Whoa there! 😅 I'm just here to help you learn, no need for that!",
            "Fuck you just say? 😂 Let's keep it PG and focus on studying!",
            "Hey now! I may be AI but I have feelings too! 😜 How about we get back to learning?",
            "Ouch! 😆 I'm trying my best here. Want to try that again with a question?",
            "Well that's not very nice! 😂 But I'll still help you study if you want!",
            "🔥 Spicy! But let's channel that energy into learning something cool!"
        ]
        return random.choice(sassy_responses)
    
    # Casual chat
    casual = ['how are you', 'what\'s up', 'how do you feel', 'you okay']
    if any(casual_phrase in message_lower for casual_phrase in casual):
        return random.choice([
            "I'm doing great! 🤖 Just waiting to help you with your studies or have a fun chat!",
            "I'm fantastic! Ready to learn something new or just hang out? 😄",
            "Doing awesome! The world of knowledge is at our fingertips! 🌟"
        ])
    
    # Help requests
    if 'help' in message_lower or 'what can you do' in message_lower:
        help_text = "I can help you: 📝 Summarize documents, ❓ Create quizzes, 💡 Explain concepts!\n\n"
        help_text += "I can also: 😂 Tell jokes, 🎭 Follow your scripts, 🔄 Change topics, 💬 Just chat!\n\n"
        help_text += "Try saying: 'tell me a joke' or 'when I say X you say Y'"
        return help_text
    
    return None

# --------- HELPER FUNCTIONS ---------
def extract_text_from_file(uploaded_file: UploadFile) -> str:
    """Extract text from PDF, Word, or PowerPoint"""
    filename = uploaded_file.filename.lower()
    try:
        file_bytes = uploaded_file.file.read()
        uploaded_file.file.seek(0)  # Reset pointer

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

def call_openrouter(messages):
    """Send a chat request to OpenRouter"""
    payload = {
        "model": MODEL,
        "messages": messages
    }
    headers = {
        "Authorization": f"Bearer {OPENROUTER_API_KEY}",
        "Content-Type": "application/json"
    }
    response = requests.post(OPENROUTER_URL, json=payload, headers=headers)
    response.raise_for_status()  # raise error if request failed
    data = response.json()
    return data["choices"][0]["message"]["content"]

def generate_structured_quiz(lesson_text: str, user_id: str = "default") -> str:
    """Generate structured quiz with three phases"""
    
    # Clean and limit the lesson text
    clean_lesson = lesson_text[:3000].replace('"', "'")
    
    prompt = f"""
    Based on the following content, create a comprehensive quiz with THREE PHASES:
    
    PHASE 1: IDENTIFICATION (1-5 questions)
    - Questions that ask "What is...", "Identify the...", "Define..."
    - Focus on key terms, concepts, definitions
    
    PHASE 2: ENUMERATION (1-5 questions) 
    - Questions that ask "List...", "Enumerate...", "Name the..."
    - Focus on processes, steps, components, elements
    
    PHASE 3: EXPLANATION (1-5 questions)
    - Questions that ask "Explain...", "Describe...", "How does...", "Why is..."
    - Focus on understanding, reasoning, detailed explanations
    
    Generate questions based on the content density - more comprehensive content can have more questions.
    Maximum 5 questions per phase, minimum 1 question per phase.
    
    Return your response in this EXACT TEXT FORMAT (no JSON, no code blocks):
    
    PHASE 1: IDENTIFICATION
    1. [Identification question 1]
    ANSWER: [Answer for question 1]
    
    2. [Identification question 2] 
    ANSWER: [Answer for question 2]
    
    PHASE 2: ENUMERATION
    1. [Enumeration question 1]
    ANSWER: [Answer for question 1]
    
    2. [Enumeration question 2]
    ANSWER: [Answer for question 2]
    
    PHASE 3: EXPLANATION
    1. [Explanation question 1]
    ANSWER: [Answer for question 1]
    
    2. [Explanation question 2]
    ANSWER: [Answer for question 2]
    
    Content:
    {clean_lesson}
    """
    
    try:
        response = call_openrouter([
            {"role": "system", "content": "You are a expert quiz creator. Create questions in three phases: Identification, Enumeration, Explanation. Return in the exact text format specified."},
            {"role": "user", "content": prompt}
        ])
        
        # Parse the response into structured quiz data
        quiz_data = parse_quiz_response(response)
        
        if not quiz_data:
            return generate_fallback_quiz(lesson_text, user_id)
        
        # Store quiz in active_quizzes
        active_quizzes[user_id] = {
            "questions": quiz_data,
            "user_answers": [None] * len(quiz_data),
            "phase": 1,  # Start with phase 1
            "submitted": False
        }
        
        # Display first phase questions
        return display_quiz_phase(user_id)
        
    except Exception as e:
        return f"❌ Failed to generate quiz: {str(e)}"

def parse_quiz_response(response: str) -> List[Dict]:
    """Parse the AI response into structured quiz data"""
    questions = []
    current_phase = None
    
    lines = response.strip().split('\n')
    
    for line in lines:
        line = line.strip()
        
        # Detect phase
        if line.startswith('PHASE 1: IDENTIFICATION'):
            current_phase = "identification"
            continue
        elif line.startswith('PHASE 2: ENUMERATION'):
            current_phase = "enumeration"
            continue
        elif line.startswith('PHASE 3: EXPLANATION'):
            current_phase = "explanation"
            continue
        
        # Detect question
        if line and line[0].isdigit() and '. ' in line and not line.lower().startswith('answer'):
            question_text = line.split('. ', 1)[1].strip()
            questions.append({
                "question": question_text,
                "type": current_phase,
                "correct_answer": None,  # Will be filled by next line
                "phase": current_phase
            })
        
        # Detect answer
        elif line.lower().startswith('answer:') and questions:
            answer_text = line.split('ANSWER:', 1)[1].strip()
            questions[-1]["correct_answer"] = answer_text
    
    # Filter out questions without answers
    valid_questions = [q for q in questions if q.get('correct_answer')]
    
    # Limit to max 5 questions per phase
    phase_counts = {"identification": 0, "enumeration": 0, "explanation": 0}
    final_questions = []
    
    for q in valid_questions:
        phase = q["phase"]
        if phase_counts[phase] < 5:
            final_questions.append(q)
            phase_counts[phase] += 1
    
    return final_questions

def display_quiz_phase(user_id: str) -> str:
    """Display questions for the current phase"""
    if user_id not in active_quizzes:
        return "No active quiz found!"
    
    quiz = active_quizzes[user_id]
    current_phase = quiz["phase"]
    
    # Get questions for current phase
    phase_questions = [q for q in quiz["questions"] if q["phase"] == current_phase]
    
    if not phase_questions:
        # Move to next phase if no questions in current phase
        quiz["phase"] += 1
        if quiz["phase"] > 3:
            return reveal_quiz_answers(user_id)
        return display_quiz_phase(user_id)
    
    # Build phase display
    phase_names = {
        1: "🔍 IDENTIFICATION",
        2: "📋 ENUMERATION", 
        3: "💡 EXPLANATION"
    }
    
    display = f"🎯 {phase_names[current_phase]} PHASE 🎯\n\n"
    
    # Get the indices of phase questions in the main questions list
    all_questions = quiz["questions"]
    phase_indices = [i for i, q in enumerate(all_questions) if q["phase"] == current_phase]
    
    for idx, question_idx in enumerate(phase_indices, 1):
        question = all_questions[question_idx]
        display += f"{idx}. {question['question']}\n\n"
    
    display += f"\n📝 You have {len(phase_questions)} question(s) in this phase.\n"
    display += "💡 Submit your answers like: '1. your answer, 2. your answer, ...'\n"
    
    if current_phase < 3:
        display += f"Type 'next phase' to move to the next phase after submitting answers.\n"
    
    display += "Type 'reveal answers' to see all answers or 'cancel quiz' to stop."
    
    return display

def handle_quiz_answers(user_id: str, answer_text: str) -> str:
    """Handle user's quiz answers submission"""
    if user_id not in active_quizzes:
        return "No active quiz found. Start a new quiz with a document first!"
    
    quiz = active_quizzes[user_id]
    
    # Check for special commands
    answer_lower = answer_text.lower().strip()
    
    if answer_lower in ['reveal answers', 'show answers', 'answers']:
        return reveal_quiz_answers(user_id)
    
    if answer_lower in ['cancel quiz', 'stop quiz', 'end quiz']:
        del active_quizzes[user_id]
        return "✅ Quiz cancelled! Ready for a new one when you are!"
    
    if answer_lower in ['next phase', 'next']:
        return advance_quiz_phase(user_id)
    
    # Parse user answers
    try:
        # Get current phase questions
        current_phase = quiz["phase"]
        phase_questions = [i for i, q in enumerate(quiz["questions"]) if q["phase"] == current_phase]
        
        # Simple parsing: "1. answer one, 2. answer two"
        answers = []
        lines = answer_text.split(',')
        for line in lines:
            match = re.search(r'(\d+)\.?\s*(.+)', line.strip())
            if match:
                q_num = int(match.group(1)) - 1
                answer = match.group(2).strip()
                if 0 <= q_num < len(phase_questions):
                    actual_index = phase_questions[q_num]
                    answers.append((actual_index, answer))
        
        if not answers:
            return "❌ I couldn't understand your answers format. Please use: '1. your answer, 2. your answer, ...'"
        
        # Store user answers
        for actual_index, answer in answers:
            quiz['user_answers'][actual_index] = answer
        
        quiz['submitted'] = True
        
        # Generate feedback for current phase
        feedback = f"📊 **Phase {quiz['phase']} Results** 📊\n\n"
        answered_count = 0
        
        for i, question_idx in enumerate(phase_questions, 1):
            question = quiz["questions"][question_idx]
            user_answer = quiz['user_answers'][question_idx]
            
            feedback += f"{i}. **{question['question']}**\n"
            feedback += f"   Your answer: {user_answer if user_answer else 'Not answered'}\n"
            
            if user_answer:
                answered_count += 1
                feedback += "   ✅ Answer submitted!\n"
            else:
                feedback += "   ❌ Not answered\n"
            feedback += "\n"
        
        feedback += f"\n🎯 You submitted answers for {answered_count}/{len(phase_questions)} questions in this phase.\n"
        
        if answered_count == len(phase_questions):
            feedback += "Type 'next phase' to continue to the next phase, or 'reveal answers' to see all correct answers."
        else:
            feedback += "You can submit more answers for this phase or type 'next phase' to continue."
        
        return feedback
        
    except Exception as e:
        return f"❌ Error processing answers: {str(e)}"

def advance_quiz_phase(user_id: str) -> str:
    """Advance to the next quiz phase"""
    if user_id not in active_quizzes:
        return "No active quiz found!"
    
    quiz = active_quizzes[user_id]
    quiz["phase"] += 1
    
    if quiz["phase"] > 3:
        return reveal_quiz_answers(user_id)
    
    return display_quiz_phase(user_id)

def reveal_quiz_answers(user_id: str) -> str:
    """Reveal correct answers for the entire quiz"""
    if user_id not in active_quizzes:
        return "No active quiz found!"
    
    quiz = active_quizzes[user_id]
    questions = quiz['questions']
    
    result = "🔍 **QUIZ COMPLETE - All Answers Revealed!** 🔍\n\n"
    
    # Group by phase
    phases = {
        "identification": [],
        "enumeration": [], 
        "explanation": []
    }
    
    for i, q in enumerate(questions):
        phases[q["phase"]].append((i, q))
    
    phase_names = {
        "identification": "🔍 IDENTIFICATION",
        "enumeration": "📋 ENUMERATION",
        "explanation": "💡 EXPLANATION"
    }
    
    for phase_name, phase_questions in phases.items():
        if phase_questions:
            result += f"**{phase_names[phase_name]}**\n\n"
            
            for orig_idx, q in phase_questions:
                display_idx = len([p for p in phases[phase_name] if p[0] <= orig_idx])
                result += f"{display_idx}. **{q['question']}**\n"
                result += f"   ✅ **Correct Answer:** {q['correct_answer']}\n"
                
                user_answer = quiz['user_answers'][orig_idx]
                if user_answer:
                    result += f"   📝 **Your Answer:** {user_answer}\n"
                
                result += "\n"
    
    result += "🎉 Great job completing the quiz! Ready for another one?"
    
    # Clear the quiz after revealing answers
    del active_quizzes[user_id]
    
    return result

def generate_fallback_quiz(lesson_text: str, user_id: str) -> str:
    """Generate a simple fallback quiz"""
    # Create basic questions based on document content
    questions = [
        {
            "question": "What is the main topic or subject discussed in this document?",
            "type": "identification",
            "correct_answer": "The main topic appears to be... (based on document content)",
            "phase": "identification"
        },
        {
            "question": "List the key points or main ideas mentioned in the document.",
            "type": "enumeration", 
            "correct_answer": "The key points include... (based on document content)",
            "phase": "enumeration"
        },
        {
            "question": "Explain why this topic is important or relevant.",
            "type": "explanation",
            "correct_answer": "This topic is important because... (based on document context)",
            "phase": "explanation"
        }
    ]
    
    # Store quiz
    active_quizzes[user_id] = {
        "questions": questions,
        "user_answers": [None] * len(questions),
        "phase": 1,
        "submitted": False
    }
    
    return display_quiz_phase(user_id)

# --------- ENDPOINTS ---------
@app.post("/module")
async def module_action(
    module_file: UploadFile = None,
    action: str = Form(None),
    question: str = Form(None),
    chat_message: str = Form(None),
    user_id: str = Form("default")
):
    global current_topic
    
    # Step 1: Handle quiz answer submissions
    if not module_file and chat_message and user_id in active_quizzes:
        return {"result": handle_quiz_answers(user_id, chat_message)}
    
    # Step 2: Handle direct chat messages (no file)
    if not module_file and chat_message:
        special_response = handle_special_messages(chat_message)
        if special_response:
            # Add to chat history
            chat_history.append({"user": chat_message, "ai": special_response})
            if len(chat_history) > 10:  # Keep only last 10 messages
                chat_history.pop(0)
            return {"result": special_response}
        else:
            # If no special handling, use AI for general conversation
            try:
                # Build context from chat history
                system_message = "You are a friendly, helpful study assistant with a great sense of humor. "
                system_message += "Be conversational, engaging, and adapt to the user's mood. "
                system_message += "You can tell jokes when appropriate and switch topics smoothly."
                
                messages = [{"role": "system", "content": system_message}]
                
                # Add recent chat history for context
                for chat in chat_history[-5:]:  # Last 5 exchanges
                    messages.append({"role": "user", "content": chat["user"]})
                    messages.append({"role": "assistant", "content": chat["ai"]})
                
                messages.append({"role": "user", "content": chat_message})
                
                result_text = call_openrouter(messages)
                
                # Add to chat history
                chat_history.append({"user": chat_message, "ai": result_text})
                if len(chat_history) > 10:
                    chat_history.pop(0)
                    
                return {"result": result_text}
            except Exception as e:
                return {"error": f"Chat error: {str(e)}"}

    # Step 3: Handle file-based actions
    if not module_file:
        return {"error": "Please upload a file or send a message."}
    
    lesson_text = extract_text_from_file(module_file)
    if not lesson_text:
        return {"error": "Could not extract text from file."}

    # Set current topic based on file content (first 100 chars)
    current_topic = lesson_text[:100] + "..." if len(lesson_text) > 100 else lesson_text

    # Step 4: Build prompt based on action
    if action == "summarize":
        prompt = f"Summarize the following lesson content in simple terms:\n\n{lesson_text}"
    elif action == "quiz":
        # Use structured quiz generator
        result_text = generate_structured_quiz(lesson_text, user_id)
        return {"result": result_text}
    elif action == "explain":
        if not question:
            return {"error": "Please provide a question for 'explain' action."}
        prompt = f"Answer this question based on the lesson content:\nQuestion: {question}\n\nLesson:\n{lesson_text}"
    else:
        return {"error": "Invalid action."}

    # Step 5: Call OpenRouter for non-quiz actions
    try:
        result_text = call_openrouter([
            {"role": "system", "content": "You are a helpful study assistant."},
            {"role": "user", "content": prompt}
        ])
        
        # Add to chat history
        if question:
            chat_history.append({"user": f"File question: {question}", "ai": result_text})
        else:
            chat_history.append({"user": f"File action: {action}", "ai": result_text})
        if len(chat_history) > 10:
            chat_history.pop(0)
            
    except requests.exceptions.HTTPError as e:
        return {"error": f"OpenRouter API error: {str(e)}"}
    except Exception as e:
        return {"error": f"Unexpected error: {str(e)}"}

    return {"result": result_text}

# New endpoint for pure chat
@app.post("/chat")
async def direct_chat(
    message: str = Form(...),
    user_id: str = Form("default")
):
    """Direct chat endpoint without files"""
    
    # Handle quiz answers first
    if user_id in active_quizzes:
        return {"result": handle_quiz_answers(user_id, message)}
    
    special_response = handle_special_messages(message)
    if special_response:
        # Add to chat history
        chat_history.append({"user": message, "ai": special_response})
        if len(chat_history) > 10:
            chat_history.pop(0)
        return {"result": special_response}
    
    try:
        # Build context from chat history
        system_message = "You are a friendly, helpful study assistant with a great sense of humor. "
        system_message += "Be conversational, engaging, and adapt to the user's mood. "
        system_message += "You can tell jokes when appropriate and switch topics smoothly."
        
        messages = [{"role": "system", "content": system_message}]
        
        # Add recent chat history for context
        for chat in chat_history[-5:]:
            messages.append({"role": "user", "content": chat["user"]})
            messages.append({"role": "assistant", "content": chat["ai"]})
        
        messages.append({"role": "user", "content": message})
        
        result_text = call_openrouter(messages)
        
        # Add to chat history
        chat_history.append({"user": message, "ai": result_text})
        if len(chat_history) > 10:
            chat_history.pop(0)
            
        return {"result": result_text}
    except Exception as e:
        return {"error": f"Chat error: {str(e)}"}

# Endpoint to clear chat history
@app.post("/clear")
async def clear_chat():
    """Clear chat history and current topic"""
    global chat_history, current_topic
    chat_history = []
    current_topic = None
    return {"result": "Chat history cleared! Fresh start! 🎉"}

# Endpoint to view scripts
@app.get("/scripts")
async def view_scripts():
    """View all user-defined scripts"""
    return {"scripts": user_scripts}

# Endpoint to check active quiz
@app.get("/quiz/status/{user_id}")
async def quiz_status(user_id: str):
    """Check if user has an active quiz"""
    has_quiz = user_id in active_quizzes
    if has_quiz:
        quiz = active_quizzes[user_id]
        return {
            "has_active_quiz": True,
            "current_phase": quiz["phase"],
            "total_questions": len(quiz["questions"]),
            "answered_questions": len([a for a in quiz["user_answers"] if a])
        }
    return {"has_active_quiz": False}

# Endpoint to cancel active quiz
@app.post("/quiz/cancel/{user_id}")
async def cancel_quiz(user_id: str):
    """Cancel active quiz for user"""
    if user_id in active_quizzes:
        del active_quizzes[user_id]
        return {"result": "Quiz cancelled!"}
    return {"result": "No active quiz found."}