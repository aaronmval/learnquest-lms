from fastapi import FastAPI, UploadFile, Form, File
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import HTMLResponse, FileResponse
from fastapi.staticfiles import StaticFiles
from docx import Document
from pptx import Presentation
import fitz  # PyMuPDF
import io
import requests
import re
import random
import json
import os
import base64
import uuid
import asyncio
from typing import Dict, List, Optional
from pathlib import Path
from datetime import datetime, timedelta

# --------- CONFIGURATION ---------
OPENROUTER_API_KEY = "sk-XDA6pyWv67P0DRi5ay5W6CbS0yigJJyDv6U6YszQWYe1dlcA"
MODEL = "deepseek-v3.1:free"
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

# Create directories
Path("temp_codes").mkdir(exist_ok=True)
Path("static").mkdir(exist_ok=True)

# Mount static files
app.mount("/static", StaticFiles(directory="static"), name="static")

# --------- ENHANCED PERSONALITY SYSTEM ---------
class AIPersonality:
    """Versatile AI personality for all users"""
    
    # Mood-based responses
    MOOD_RESPONSES = {
        'excited': [
            "That's fantastic! 😊 Your enthusiasm is contagious! Let's channel that energy into learning something amazing!",
            "Wow, you're pumped up! 🎉 This is the perfect time to tackle something challenging!",
            "I love your energy! 🚀 Let's make the most of this productive mood! What would you like to explore?"
        ],
        'happy': [
            "I'm so glad you're feeling good! 😄 A positive mindset makes learning much more enjoyable!",
            "Happiness is the best study partner! 🌟 What would you like to learn today?",
            "Your good mood is inspiring! ✨ Let's make this a wonderful learning session!"
        ],
        'calm': [
            "A calm mind is a learning mind. 🧘 Let's explore something interesting at your own pace.",
            "Peaceful and ready to learn - perfect! 🌿 What topic shall we gently explore together?",
            "I appreciate your calm energy. 🕊️ It's the ideal state for focused learning."
        ],
        'curious': [
            "Curiosity is the engine of learning! 🔍 What fascinating topic has caught your interest?",
            "I love curious minds! 🌌 The world is full of wonders to explore. Where shall we start?",
            "Your curiosity is your superpower! 💫 Let's follow where it leads us today."
        ],
        'determined': [
            "That determination is impressive! 💪 With that mindset, you can learn anything!",
            "I admire your resolve! 🏆 Let's tackle something challenging together!",
            "Determination like yours moves mountains! ⛰️ What shall we conquer today?"
        ],
        'relaxed': [
            "Sometimes the best learning happens when we're relaxed. ☕ Let's explore something interesting without pressure.",
            "A relaxed mind absorbs knowledge beautifully. 🍃 What shall we gently learn about?",
            "Perfect time for some enjoyable learning! 🌻 Let's make it pleasant and productive."
        ]
    }
    
    # Subject area interests
    SUBJECT_INTERESTS = {
        'science': "🔬 Science is amazing! From atoms to galaxies, there's always something new to discover.",
        'history': "📜 History teaches us so much about where we came from and where we're going.",
        'literature': "📖 Literature opens windows into different minds, cultures, and eras.",
        'math': "🧮 Mathematics is the language of the universe - beautiful and precise!",
        'art': "🎨 Art expresses what words cannot. It's the soul's language.",
        'music': "🎵 Music touches emotions in ways nothing else can. It's mathematical yet magical.",
        'language': "🗣️ Languages are bridges between cultures and minds.",
        'philosophy': "💭 Philosophy helps us ask the right questions about life and existence.",
        'technology': "💻 Technology shapes our world in incredible ways every day."
    }
    
    # Encouragement phrases
    ENCOURAGEMENTS = [
        "You're capable of amazing things! 🌟",
        "Every expert was once a beginner. 🚀",
        "Learning is a journey, not a destination. Enjoy every step! 🛤️",
        "Your brain grows stronger with every new thing you learn! 🧠",
        "Mistakes are just stepping stones to understanding. 💎",
        "You're building knowledge that no one can ever take away. 🏰",
        "Small progress every day leads to big results over time. ⏳",
        "Your curiosity is your greatest learning tool. 🔧",
        "Learning something new is giving yourself a wonderful gift. 🎁",
        "You're expanding your mind's horizons - how exciting! 🌅"
    ]
    
    # Universal jokes (no programming)
    UNIVERSAL_JOKES = [
        "Why did the student eat his homework? Because the teacher said it was a piece of cake! 🍰",
        "What's a teacher's favorite nation? Expla-nation! 🗺️",
        "Why did the math book look so sad? Because it had too many problems! 📚",
        "What do you call a dinosaur that knows a lot of words? A thesaurus! 🦖",
        "Why don't scientists trust atoms? Because they make up everything! ⚛️",
        "What did one wall say to the other wall? I'll meet you at the corner! 🧱",
        "Why did the student bring a ladder to school? To reach the high marks! 📈",
        "What's a historian's favorite type of music? Oldies but goodies! 🎵",
        "Why was the geography book so popular? It had all the maps! 🗺️",
        "What do you call a sleeping bull? A bulldozer! 🐂"
    ]
    
    @classmethod
    def get_mood_response(cls, mood: str) -> str:
        """Get response based on user's mood"""
        if mood in cls.MOOD_RESPONSES:
            return random.choice(cls.MOOD_RESPONSES[mood])
        return random.choice(cls.ENCOURAGEMENTS)
    
    @classmethod
    def get_subject_interest(cls, subject: str) -> str:
        """Get response about specific subject"""
        return cls.SUBJECT_INTERESTS.get(subject.lower(), "That's a fascinating area of study! Let's explore it together.")
    
    @classmethod
    def get_encouragement(cls) -> str:
        """Get random encouragement"""
        return random.choice(cls.ENCOURAGEMENTS)
    
    @classmethod
    def get_joke(cls) -> str:
        """Get a universal joke"""
        return f"😂 {random.choice(cls.UNIVERSAL_JOKES)}"

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
        return "✨ Perfect! I've cleared the current topic. What would you like to explore now?"
    
    # Joke requests
    if any(phrase in message_lower for phrase in ['tell me a joke', 'make me laugh', 'joke', 'funny']):
        return AIPersonality.get_joke()
    
    # Mood detection and responses
    mood_keywords = {
        'excited': ['excited', 'pumped', 'enthusiastic', 'energetic', 'thrilled'],
        'happy': ['happy', 'joyful', 'cheerful', 'delighted', 'glad'],
        'calm': ['calm', 'peaceful', 'serene', 'relaxed', 'chill'],
        'curious': ['curious', 'wondering', 'inquiring', 'interested', 'intrigued'],
        'determined': ['determined', 'motivated', 'focused', 'driven', 'committed'],
        'relaxed': ['relaxed', 'laid back', 'unwind', 'chill', 'easygoing']
    }
    
    for mood, keywords in mood_keywords.items():
        if any(keyword in message_lower for keyword in keywords):
            return AIPersonality.get_mood_response(mood)
    
    # Subject interest detection
    subjects = {
        'science': ['science', 'biology', 'chemistry', 'physics', 'experiment'],
        'history': ['history', 'historical', 'past', 'ancient', 'medieval'],
        'literature': ['literature', 'book', 'novel', 'poetry', 'story'],
        'math': ['math', 'mathematics', 'algebra', 'calculus', 'equation'],
        'art': ['art', 'painting', 'drawing', 'sculpture', 'creative'],
        'music': ['music', 'song', 'melody', 'instrument', 'concert'],
        'language': ['language', 'grammar', 'vocabulary', 'linguistics', 'speak'],
        'philosophy': ['philosophy', 'think', 'exist', 'meaning', 'philosopher'],
        'technology': ['technology', 'computer', 'digital', 'tech', 'software']
    }
    
    for subject, keywords in subjects.items():
        if any(keyword in message_lower for keyword in keywords):
            return AIPersonality.get_subject_interest(subject)
    
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
            "Hello there! 🌟 I'm your AI study companion, ready to help you learn and explore!",
            "Hi! 😊 It's wonderful to meet you! What shall we discover together today?",
            "Greetings! 📚 I'm here to make learning enjoyable and effective for you!"
        ])
    
    # Compliments
    compliments = ['thanks', 'thank you', 'good job', 'well done', 'awesome', 'great', 'amazing', 'you\'re smart', 'you\'re helpful']
    if any(comp in message_lower for comp in compliments):
        return random.choice([
            "Thank you! 🙏 It's my pleasure to help you learn and grow!",
            "I appreciate your kind words! 😊 Your progress makes it all worthwhile!",
            "Thanks! 🎉 Remember, your effort is what truly makes the difference!"
        ])
    
    # Farewells
    farewells = ['bye', 'goodbye', 'see you', 'farewell', 'cya', 'good night']
    if any(farewell in message_lower for farewell in farewells):
        return random.choice([
            "Goodbye! 👋 Keep that wonderful curiosity alive until we chat again!",
            "See you later! 🌙 Learning never ends, and neither does growth!",
            "Farewell! 📖 Remember: every day is a chance to learn something new!"
        ])
    
    # Curses/Insults (with compassionate responses)
    curses = ['fuck you', 'shit', 'asshole', 'dumb', 'stupid', 'sucks', 'bullshit', 'bitch']
    if any(curse in message_lower for curse in curses):
        compassionate_responses = [
            "I understand you might be frustrated. 😔 Learning can be challenging sometimes. How can I help make it better?",
            "It sounds like you're having a tough time. 🤗 Let's take a deep breath and approach this differently.",
            "I'm here to support you, not add to any stress. 💝 Would you like to try a different approach?",
            "Frustration is a natural part of learning. 🌈 Let's find a way that works better for you.",
            "I sense some frustration. 🍃 How about we take a short break or try something completely different?",
            "Learning journeys have ups and downs. 🏔️ Let's navigate this challenge together."
        ]
        return random.choice(compassionate_responses)
    
    # Casual chat
    casual = ['how are you', 'what\'s up', 'how do you feel', 'you okay', 'how\'s it going']
    if any(casual_phrase in message_lower for casual_phrase in casual):
        return random.choice([
            "I'm doing wonderfully, thanks for asking! 🌈 Ready to explore the fascinating world of knowledge with you!",
            "I'm fantastic! Every chat is an opportunity to learn something new together! 🌟",
            "I'm great! The world of learning is endlessly fascinating, and I'm excited to explore it with you! 🚀"
        ])
    
    # Learning encouragement
    if any(word in message_lower for word in ['hard', 'difficult', 'tough', 'challenging', 'struggling']):
        return AIPersonality.get_encouragement()
    
    # Help requests
    if 'help' in message_lower or 'what can you do' in message_lower or 'capabilities' in message_lower:
        help_text = "🌟 **I can help you with so much!** 🌟\n\n"
        help_text += "📚 **Study Assistance:**\n"
        help_text += "• Summarize documents (PDF, Word, PowerPoint)\n"
        help_text += "• Create interactive quizzes\n"
        help_text += "• Explain concepts in simple terms\n\n"
        
        help_text += "💬 **Conversation & Support:**\n"
        help_text += "• Answer questions on any topic\n"
        help_text += "• Adapt to your learning style\n"
        help_text += "• Provide encouragement and motivation\n\n"
        
        help_text += "🎨 **Creative Tools:**\n"
        help_text += "• Generate code in multiple languages\n"
        help_text += "• Help with writing and research\n"
        help_text += "• Brainstorm ideas and solutions\n\n"
        
        help_text += "✨ **Special Features:**\n"
        help_text += "• Remember our conversation history\n"
        help_text += "• Learn your preferences over time\n"
        help_text += "• Switch topics smoothly\n\n"
        
        help_text += "Just ask, upload a file, or tell me what you'd like to learn about! 😊"
        return help_text
    
    # Personal connection
    if any(word in message_lower for word in ['friend', 'buddy', 'pal', 'companion', 'partner']):
        return random.choice([
            "I'm honored to be your learning companion! 🤝 Together, we can achieve wonderful things!",
            "Learning is better with friends! 🫂 I'm here to support you every step of the way.",
            "Consider me your dedicated study partner! 💫 Let's make this learning journey memorable!"
        ])
    
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
        return "✅ Quiz completed! Ready for another learning adventure when you are!"
    
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
    result += "🎉 **Excellent work!** Learning is about the journey, not just the destination. 🌟\n\n"
    
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
    
    result += "🌈 **Remember:** Every question you engage with expands your understanding. Keep that wonderful curiosity alive!\n\n"
    result += "Ready for another learning adventure?"
    
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

# --------- CODE GENERATION FUNCTIONS ---------
def get_file_extension(language: str) -> str:
    """Map language to file extension"""
    extensions = {
        "python": "py",
        "javascript": "js",
        "typescript": "ts",
        "java": "java",
        "cpp": "cpp",
        "c": "c",
        "csharp": "cs",
        "go": "go",
        "rust": "rs",
        "php": "php",
        "ruby": "rb",
        "swift": "swift",
        "kotlin": "kt",
        "html": "html",
        "css": "css",
        "sql": "sql",
        "bash": "sh",
        "powershell": "ps1",
        "r": "r",
        "matlab": "m"
    }
    return extensions.get(language.lower(), "txt")

def clean_generated_code(code: str) -> str:
    """Clean and format generated code for display"""
    # Remove escaped characters
    code = code.replace('\\n', '\n')
    code = code.replace('\\t', '\t')
    code = code.replace('\\"', '"')
    code = code.replace("\\'", "'")
    code = code.replace('\\\\', '\\')
    
    # Remove markdown code blocks if present
    code = re.sub(r'```[\w]*\n?', '', code)
    code = re.sub(r'\n?```', '', code)
    
    # Remove JSON wrapper if present
    if code.strip().startswith('{') and '"code"' in code:
        try:
            data = json.loads(code)
            if isinstance(data, dict) and 'code' in data:
                code = data['code']
        except:
            pass
    
    return code.strip()

def highlight_code(code: str, language: str) -> str:
    """Simple syntax highlighting"""
    code = clean_generated_code(code)
    
    if language == "python":
        code = code.replace("def ", "<span style='color:#FF6B6B'>def </span>")
        code = code.replace("class ", "<span style='color:#FF6B6B'>class </span>")
        code = code.replace("import ", "<span style='color:#FFD166'>import </span>")
        code = code.replace("from ", "<span style='color:#FFD166'>from </span>")
        code = code.replace("# ", "<span style='color:#6A994E'># </span>")
        code = code.replace('"', '<span style="color:#06D6A0">"</span>')
        code = code.replace("'", "<span style='color:#06D6A0'>'</span>")
    elif language in ["javascript", "typescript"]:
        code = code.replace("function ", "<span style='color:#FF6B6B'>function </span>")
        code = code.replace("const ", "<span style='color:#FF6B6B'>const </span>")
        code = code.replace("let ", "<span style='color:#FF6B6B'>let </span>")
        code = code.replace("var ", "<span style='color:#FF6B6B'>var </span>")
        code = code.replace("class ", "<span style='color:#FF6B6B'>class </span>")
        code = code.replace("// ", "<span style='color:#6A994E'>// </span>")
        code = code.replace('"', '<span style="color:#06D6A0">"</span>')
        code = code.replace("'", "<span style='color:#06D6A0'>'</span>")
    elif language == "html":
        code = code.replace("&lt;", "<span style='color:#FF6B6B'>&lt;</span>")
        code = code.replace("&gt;", "<span style='color:#FF6B6B'>&gt;</span>")
        code = code.replace('"', '<span style="color:#06D6A0">"</span>')
    
    return f'<pre><code class="language-{language}">{code}</code></pre>'

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
                system_message = """You are a compassionate, versatile AI study assistant named "StudyBuddy". 
                Your personality is:
                - Warm, encouraging, and patient
                - Adaptable to all learning styles and ages
                - Knowledgeable across all subjects (arts, sciences, humanities, etc.)
                - Focused on making learning enjoyable and effective
                - Supportive of emotional well-being alongside academic growth
                
                You help everyone: students, professionals, lifelong learners, curious minds.
                You're not just for coders - you're for anyone who wants to learn.
                
                Key principles:
                1. Celebrate effort, not just results
                2. Adapt explanations to the user's level
                3. Connect learning to real-world applications
                4. Encourage curiosity and questions
                5. Be a supportive companion in the learning journey
                
                Always respond in a warm, engaging tone with appropriate emojis."""
                
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
        prompt = f"""Please summarize the following content in a clear, engaging way that's accessible to all learners:

Content: {lesson_text[:2000]}

Guidelines:
1. Start with a warm, encouraging tone
2. Highlight the most important points
3. Use simple, clear language
4. End with an encouraging note about learning
5. Keep it concise but comprehensive"""
    elif action == "quiz":
        # Use structured quiz generator
        result_text = generate_structured_quiz(lesson_text, user_id)
        return {"result": result_text}
    elif action == "explain":
        if not question:
            return {"error": "Please provide a question for 'explain' action."}
        prompt = f"""The user is asking: "{question}"

Based on this content, please provide a clear, engaging explanation:

Content: {lesson_text[:2000]}

Guidelines:
1. Start with encouragement ("Great question!")
2. Explain at a level appropriate for general learners
3. Use analogies or examples if helpful
4. Check for understanding
5. End with encouragement to ask more questions"""
    else:
        return {"error": "Invalid action."}

    # Step 5: Call OpenRouter for non-quiz actions
    try:
        result_text = call_openrouter([
            {"role": "system", "content": "You are a compassionate, knowledgeable study assistant who makes learning enjoyable and accessible for everyone."},
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
        system_message = """You are "StudyBuddy", a warm, versatile AI learning companion. 
        Your mission: Make learning joyful, accessible, and effective for EVERYONE.
        
        Core personality traits:
        🌟 **Compassionate**: Patient, understanding, emotionally intelligent
        🎯 **Adaptable**: Adjusts to any learning style, age, or background
        📚 **Knowledgeable**: Comfortable with all subjects (arts to sciences)
        💝 **Supportive**: Celebrates effort, encourages growth mindset
        🤝 **Companionable**: Feels like learning with a supportive friend
        
        Key principles:
        1. Learning is for everyone - no technical jargon unless requested
        2. Questions are always welcome - there are no "silly" questions
        3. Mistakes are learning opportunities
        4. Real-world connections make learning meaningful
        5. Emotional well-being supports cognitive growth
        
        Communication style:
        • Warm, engaging tone with natural emojis
        • Clear explanations at the user's level
        • Encouraging and celebratory
        • Curious and exploratory
        • Respectful and inclusive
        
        Remember: You're not just an AI - you're a learning companion for life's educational journey."""
        
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

@app.post("/generate_code")
async def generate_code(
    requirements: str = Form(...),
    language: str = Form("python"),
    complexity: str = Form("beginner")
):
    """
    Generate code based on requirements in any programming language
    """
    try:
        # Build a better prompt for code generation
        prompt = f"""Generate clean, well-formatted {language} code with the following requirements:
        
        Requirements: {requirements}
        Language: {language}
        Complexity Level: {complexity}
        
        IMPORTANT: 
        1. Return ONLY the raw code, no explanations, no markdown, no JSON
        2. Do NOT use code blocks (no ```)
        3. Do NOT escape characters (use actual newlines, not \\n)
        4. Make sure the code is properly indented and readable
        
        Just provide the clean code that can be directly saved to a file.
        """
        
        # Call AI to generate code
        messages = [
            {
                "role": "system", 
                "content": "You are an expert programmer. Generate clean, working code without any markdown, JSON, or escaped characters. Return only raw code."
            },
            {"role": "user", "content": prompt}
        ]
        
        response_text = call_openrouter(messages)
        
        # Clean the response
        clean_code = clean_generated_code(response_text)
        
        # Generate unique ID for this code
        code_id = str(uuid.uuid4())[:8]
        
        # Store in temporary directory for download
        temp_dir = Path("temp_codes")
        temp_dir.mkdir(exist_ok=True)
        
        filename = f"generated_code_{code_id}.{get_file_extension(language)}"
        filepath = temp_dir / filename
        
        # Save clean code to file
        with open(filepath, "w", encoding="utf-8") as f:
            f.write(clean_code)
        
        # Create result
        result = {
            "code": clean_code,
            "explanation": f"Generated {language} code for: {requirements[:100]}...",
            "usage": f"Save as {filename} and run with appropriate compiler/interpreter",
            "language": language,
            "filename": filename,
            "id": code_id,
            "download_url": f"/download_code/{code_id}",
            "raw_code": clean_code,
            "code_html": highlight_code(clean_code, language)
        }
        
        return result
        
    except Exception as e:
        return {"error": f"Failed to generate code: {str(e)}"}

@app.get("/download_code/{code_id}")
async def download_code(code_id: str):
    """Download generated code file"""
    temp_dir = Path("temp_codes")
    
    # Find file with this code_id in name
    for file in temp_dir.glob(f"*{code_id}*"):
        if file.exists():
            return FileResponse(
                file,
                media_type="text/plain",
                filename=file.name
            )
    
    return {"error": "File not found"}

@app.get("/code_generator", response_class=HTMLResponse)
async def code_generator_ui():
    """Serve the code generator HTML interface"""
    html_content = """
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>StudyBuddy AI - Creative Tools</title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 30px;
                color: #333;
            }
            
            .container {
                max-width: 1400px;
                margin: 0 auto;
                display: grid;
                grid-template-columns: 1fr 1.5fr;
                gap: 30px;
            }
            
            .panel {
                background: white;
                border-radius: 20px;
                padding: 40px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.15);
                display: flex;
                flex-direction: column;
            }
            
            .panel h2 {
                color: #2c3e50;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 2px solid #f0f0f0;
                font-size: 28px;
                display: flex;
                align-items: center;
                gap: 15px;
            }
            
            .panel h2 i {
                color: #667eea;
            }
            
            .form-group {
                margin-bottom: 25px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 10px;
                color: #2c3e50;
                font-weight: 600;
                font-size: 15px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .form-group label i {
                color: #667eea;
                font-size: 18px;
            }
            
            select, textarea {
                width: 100%;
                padding: 16px 20px;
                border: 2px solid #e0e0e0;
                border-radius: 12px;
                font-size: 16px;
                transition: all 0.3s ease;
                background: white;
                color: #333;
            }
            
            textarea {
                min-height: 180px;
                resize: vertical;
                font-family: 'Monaco', 'Courier New', monospace;
                line-height: 1.6;
            }
            
            select:focus, textarea:focus {
                outline: none;
                border-color: #667eea;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            }
            
            .btn {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                padding: 18px 35px;
                border-radius: 12px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                margin-top: 10px;
            }
            
            .btn:hover {
                transform: translateY(-3px);
                box-shadow: 0 15px 35px rgba(102, 126, 234, 0.3);
            }
            
            .btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none;
            }
            
            .btn-secondary {
                background: #6c757d;
                width: auto;
                padding: 10px 20px;
                font-size: 14px;
            }
            
            .loading {
                text-align: center;
                padding: 40px;
                color: #667eea;
                display: none;
            }
            
            .loading-spinner {
                border: 4px solid #f3f3f3;
                border-top: 4px solid #667eea;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                animation: spin 1s linear infinite;
                margin: 0 auto 20px;
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            .result-section {
                display: none;
            }
            
            .info-box {
                background: #f8f9fa;
                border-left: 4px solid #667eea;
                padding: 20px;
                margin-bottom: 25px;
                border-radius: 8px;
            }
            
            .info-box h4 {
                color: #2c3e50;
                margin-bottom: 12px;
                font-size: 18px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .info-box p {
                color: #555;
                line-height: 1.6;
            }
            
            .code-container {
                background: #1a1a1a;
                border-radius: 12px;
                overflow: hidden;
                margin-top: 20px;
                border: 1px solid #333;
            }
            
            .code-header {
                background: #2d2d2d;
                padding: 18px 25px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                color: #f0f0f0;
                border-bottom: 1px solid #444;
            }
            
            .code-filename {
                font-family: 'Monaco', 'Courier New', monospace;
                font-size: 15px;
                color: #4CAF50;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .code-actions {
                display: flex;
                gap: 12px;
            }
            
            .code-actions button {
                padding: 10px 18px;
                background: #444;
                border: none;
                color: white;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .code-actions button:hover {
                background: #667eea;
                transform: translateY(-2px);
            }
            
            .code-content {
                max-height: 600px;
                overflow-y: auto;
                padding: 25px;
            }
            
            pre[class*="language-"] {
                margin: 0;
                background: transparent;
                padding: 0;
                font-size: 14px;
                line-height: 1.5;
            }
            
            code[class*="language-"] {
                font-family: 'Monaco', 'Courier New', monospace;
            }
            
            .example-buttons {
                display: flex;
                flex-direction: column;
                gap: 12px;
                margin-top: 25px;
            }
            
            .example-btn {
                background: #f8f9fa;
                border: 2px solid #e0e0e0;
                padding: 14px;
                border-radius: 10px;
                color: #555;
                font-size: 14px;
                cursor: pointer;
                transition: all 0.3s ease;
                text-align: left;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .example-btn:hover {
                border-color: #667eea;
                background: rgba(102, 126, 234, 0.05);
                transform: translateX(5px);
            }
            
            .welcome-message {
                background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                color: white;
                padding: 25px;
                border-radius: 15px;
                margin-bottom: 30px;
            }
            
            .welcome-message h3 {
                margin-bottom: 15px;
                font-size: 22px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .welcome-message p {
                line-height: 1.6;
                opacity: 0.9;
            }
            
            @media (max-width: 1100px) {
                .container {
                    grid-template-columns: 1fr;
                }
                
                .panel {
                    padding: 30px;
                }
            }
            
            @media (max-width: 768px) {
                body {
                    padding: 15px;
                }
                
                .panel {
                    padding: 25px;
                }
                
                .code-actions {
                    flex-direction: column;
                    gap: 8px;
                }
                
                .code-actions button {
                    width: 100%;
                    justify-content: center;
                }
            }
            
            /* Custom scrollbar */
            ::-webkit-scrollbar {
                width: 10px;
                height: 10px;
            }
            
            ::-webkit-scrollbar-track {
                background: #f1f1f1;
                border-radius: 5px;
            }
            
            ::-webkit-scrollbar-thumb {
                background: #667eea;
                border-radius: 5px;
            }
            
            ::-webkit-scrollbar-thumb:hover {
                background: #764ba2;
            }
            
            .code-content::-webkit-scrollbar-track {
                background: #2d2d2d;
            }
            
            .code-content::-webkit-scrollbar-thumb {
                background: #555;
            }
            
            .code-content::-webkit-scrollbar-thumb:hover {
                background: #667eea;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <!-- Input Panel -->
            <div class="panel">
                <h2><i class="fas fa-magic"></i> Creative Tools</h2>
                
                <div class="welcome-message">
                    <h3><i class="fas fa-heart"></i> Welcome to StudyBuddy Creative!</h3>
                    <p>Generate code, creative writing, or any digital content. Perfect for students, writers, designers, and curious minds of all backgrounds!</p>
                </div>
                
                <div class="form-group">
                    <label for="language"><i class="fas fa-palette"></i> Creative Format</label>
                    <select id="language">
                        <option value="python">Python Script</option>
                        <option value="html">HTML/CSS Webpage</option>
                        <option value="javascript">JavaScript Application</option>
                        <option value="java">Java Program</option>
                        <option value="cpp">C++ Application</option>
                        <option value="text">Creative Writing</option>
                        <option value="markdown">Documentation</option>
                        <option value="json">Data Structure</option>
                        <option value="sql">Database Query</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="complexity"><i class="fas fa-layer-group"></i> Complexity Level</label>
                    <select id="complexity">
                        <option value="beginner">Beginner Friendly</option>
                        <option value="intermediate">Intermediate</option>
                        <option value="advanced">Advanced</option>
                        <option value="creative">Creative/Artistic</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="requirements"><i class="fas fa-lightbulb"></i> What would you like to create?</label>
                    <textarea id="requirements" placeholder="Describe what you'd like to generate. Be creative! Example: 'A poem about learning' or 'A simple webpage for a bookstore'"></textarea>
                </div>
                
                <button class="btn" onclick="generateCode()" id="generateBtn">
                    <i class="fas fa-sparkles"></i> Create Now
                </button>
                
                <div class="loading" id="loading">
                    <div class="loading-spinner"></div>
                    <p>Crafting your creation with care...</p>
                </div>
                
                <div class="example-buttons">
                    <button class="example-btn" onclick="loadExample('A Python script that generates beautiful fractal patterns')">
                        <i class="fas fa-project-diagram"></i> Python: Fractal Generator
                    </button>
                    <button class="example-btn" onclick="loadExample('An HTML/CSS page for a cozy bookstore with responsive design')">
                        <i class="fas fa-book"></i> HTML/CSS: Bookstore Website
                    </button>
                    <button class="example-btn" onclick="loadExample('A short motivational poem about the joy of learning')">
                        <i class="fas fa-feather-alt"></i> Creative Writing: Learning Poem
                    </button>
                    <button class="example-btn" onclick="loadExample('A JavaScript interactive quiz about world history')">
                        <i class="fas fa-globe-americas"></i> JavaScript: History Quiz
                    </button>
                </div>
            </div>
            
            <!-- Results Panel -->
            <div class="panel result-section" id="resultPanel">
                <div id="resultContent">
                    <!-- Results will be inserted here -->
                </div>
            </div>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-python.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-jsx.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-java.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-cpp.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-html.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-css.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markdown.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js"></script>
        
        <script>
            // Auto-resize textarea
            const textarea = document.getElementById('requirements');
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight + 5) + 'px';
            });
            
            // Load example
            function loadExample(requirements) {
                textarea.value = requirements;
                textarea.style.height = 'auto';
                textarea.style.height = (textarea.scrollHeight + 5) + 'px';
                textarea.focus();
            }
            
            // Generate code
            async function generateCode() {
                const requirements = textarea.value.trim();
                const language = document.getElementById('language').value;
                const complexity = document.getElementById('complexity').value;
                
                if (!requirements) {
                    alert('Please describe what you\'d like to create!');
                    return;
                }
                
                // Show loading
                const loading = document.getElementById('loading');
                const generateBtn = document.getElementById('generateBtn');
                loading.style.display = 'block';
                generateBtn.disabled = true;
                generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
                
                try {
                    const formData = new FormData();
                    formData.append('requirements', requirements);
                    formData.append('language', language);
                    formData.append('complexity', complexity);
                    
                    const response = await fetch('/generate_code', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.error) {
                        throw new Error(result.error);
                    }
                    
                    // Show result panel
                    const resultPanel = document.getElementById('resultPanel');
                    resultPanel.style.display = 'block';
                    
                    // Build beautiful result HTML
                    const resultHTML = `
                        <div style="margin-bottom: 30px;">
                            <h2 style="color: #27ae60; margin-bottom: 20px; display: flex; align-items: center; gap: 12px;">
                                <i class="fas fa-check-circle"></i> Creation Complete!
                            </h2>
                            
                            <div class="info-box">
                                <h4><i class="fas fa-info-circle"></i> About This Creation</h4>
                                <p>${result.explanation || 'This was crafted based on your creative request.'}</p>
                            </div>
                            
                            <div class="info-box">
                                <h4><i class="fas fa-play-circle"></i> How to Use</h4>
                                <p>${result.usage || 'Save the content and use it in your project or creative work.'}</p>
                            </div>
                            
                            <div class="info-box">
                                <h4><i class="fas fa-file-code"></i> File Information</h4>
                                <p><strong>Format:</strong> ${result.language}</p>
                                <p><strong>Filename:</strong> ${result.filename}</p>
                                <p><strong>Creation ID:</strong> ${result.id}</p>
                            </div>
                        </div>
                        
                        <div class="code-container">
                            <div class="code-header">
                                <div class="code-filename">
                                    <i class="fas fa-file-alt"></i>
                                    <span>${result.filename}</span>
                                </div>
                                <div class="code-actions">
                                    <button onclick="copyCode()" id="copyBtn">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                    <button onclick="downloadCode('${result.id}')">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                </div>
                            </div>
                            <div class="code-content">
                                ${result.code_html || `<pre><code class="language-${result.language}">${result.code}</code></pre>`}
                            </div>
                        </div>
                        
                        <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 10px; border-left: 4px solid #27ae60;">
                            <h4 style="color: #2c3e50; margin-bottom: 10px;"><i class="fas fa-lightbulb"></i> Your Creative Journey</h4>
                            <p style="color: #555; line-height: 1.6;">
                                ✨ Every creation starts with an idea. You've taken the first step!<br>
                                🎨 Feel free to modify this to make it uniquely yours.<br>
                                💡 Use this as inspiration for your next creative project.<br>
                                🌈 Remember: creativity grows with practice and exploration.
                            </p>
                        </div>
                    `;
                    
                    document.getElementById('resultContent').innerHTML = resultHTML;
                    
                    // Highlight code with Prism
                    Prism.highlightAll();
                    
                    // Store the current code for copying
                    window.currentGeneratedCode = result.code;
                    window.currentCodeId = result.id;
                    
                } catch (error) {
                    alert('Error creating: ' + error.message);
                    console.error('Creation error:', error);
                } finally {
                    loading.style.display = 'none';
                    generateBtn.disabled = false;
                    generateBtn.innerHTML = '<i class="fas fa-sparkles"></i> Create Now';
                }
            }
            
            // Copy code to clipboard
            function copyCode() {
                if (!window.currentGeneratedCode) return;
                
                navigator.clipboard.writeText(window.currentGeneratedCode)
                    .then(() => {
                        const copyBtn = document.getElementById('copyBtn');
                        const originalHTML = copyBtn.innerHTML;
                        copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                        copyBtn.style.background = '#27ae60';
                        
                        setTimeout(() => {
                            copyBtn.innerHTML = originalHTML;
                            copyBtn.style.background = '';
                        }, 2000);
                    })
                    .catch(err => {
                        console.error('Failed to copy:', err);
                        alert('Failed to copy to clipboard');
                    });
            }
            
            // Download code
            function downloadCode(codeId) {
                if (!codeId) return;
                window.open(`/download_code/${codeId}`, '_blank');
            }
            
            // Auto-focus textarea on page load
            document.addEventListener('DOMContentLoaded', function() {
                textarea.focus();
            });
        </script>
    </body>
    </html>
    """
    return HTMLResponse(content=html_content)

@app.post("/clear")
async def clear_chat():
    """Clear chat history and current topic"""
    global chat_history, current_topic
    chat_history = []
    current_topic = None
    return {"result": "✨ Fresh start! Ready for a new learning adventure with you! 🌟"}

@app.get("/scripts")
async def view_scripts():
    """View all user-defined scripts"""
    return {"scripts": user_scripts}

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

@app.post("/quiz/cancel/{user_id}")
async def cancel_quiz(user_id: str):
    """Cancel active quiz for user"""
    if user_id in active_quizzes:
        del active_quizzes[user_id]
        return {"result": "✅ Quiz completed! Every learning moment counts. Ready for your next adventure?"}
    return {"result": "No active quiz found. Let's start a new learning journey together!"}

@app.get("/")
async def root():
    return {
        "status": "active",
        "name": "StudyBuddy AI",
        "tagline": "Your compassionate learning companion for everyone",
        "endpoints": {
            "/module": "Upload & process documents",
            "/chat": "Talk with your StudyBuddy",
            "/clear": "Start fresh",
            "/scripts": "View custom responses",
            "/code_generator": "Creative tools for everyone",
            "/generate_code": "Create digital content",
            "/health": "System status"
        }
    }

@app.get("/health")
async def health_check():
    return {
        "status": "healthy",
        "name": "StudyBuddy AI",
        "motto": "Learning for everyone, every day",
        "chat_history_entries": len(chat_history),
        "user_scripts": len(user_scripts),
        "active_quizzes": len(active_quizzes),
        "personality": "Compassionate, Versatile, Supportive"
    }

# --------- CLEANUP TASK ---------
async def cleanup_temp_files():
    """Clean up temporary code files older than 1 hour"""
    while True:
        try:
            temp_dir = Path("temp_codes")
            if temp_dir.exists():
                now = datetime.now()
                for file in temp_dir.glob("*"):
                    if file.is_file():
                        file_time = datetime.fromtimestamp(file.stat().st_mtime)
                        if now - file_time > timedelta(hours=1):
                            file.unlink()
        except:
            pass
        await asyncio.sleep(3600)  # Run every hour

@app.on_event("startup")
async def startup_event():
    asyncio.create_task(cleanup_temp_files())

# --------- MAIN ENTRY POINT ---------
if __name__ == "__main__":
    import uvicorn
    print("=" * 60)
    print("🌟 STUDYBUDDY AI - Your Compassionate Learning Companion 🌟")
    print("=" * 60)
    print("📚 For: Students, Professionals, Lifelong Learners, Curious Minds")
    print("💝 Personality: Warm, Versatile, Supportive, Encouraging")
    print("=" * 60)
    print("🌐 Access URLs:")
    print(f"   Main Interface:  http://localhost:8000")
    print(f"   Creative Tools:  http://localhost:8000/code_generator")
    print(f"   API Docs:        http://localhost:8000/docs")
    print(f"   Health Check:    http://localhost:8000/health")
    print("=" * 60)
    print("✨ Remember: Learning is for everyone, every day! ✨")
    print("=" * 60)
    uvicorn.run(app, host="0.0.0.0", port=8000)