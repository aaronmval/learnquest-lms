# LearnQuest — Claude Code Project Context & AI Implementation Guide

## 1. Project Identity

**Project:** LearnQuest  
**Type:** AI-powered Adaptive Learning Management System (LMS)  
**Academic project:** BSIT Capstone Project  
**Institution:** Kapitolyo High School  
**Target learners:** Grade 11 and Grade 12 STEM students  
**Initial subject focus:** Science, with the initial implementation/testing focused on Chemistry.

LearnQuest combines LMS functionality with Artificial Intelligence, Bayesian Knowledge Tracing (BKT), learning analytics, personalized feedback, and adaptive learning recommendations.

This file is implementation context for Claude Code. Preserve the research design and terminology described here.

---

## 2. Core AI Architecture

LearnQuest intentionally uses a hybrid AI approach:

### A. Bayesian Knowledge Tracing (BKT)

BKT is responsible for estimating the student's mastery of individual Science competencies.

It processes observable learning interactions such as:

- Correct/incorrect quiz responses
- Assessment interactions
- Practice attempts
- Learning activity responses

BKT uses:

- `P(L0)` — initial probability that the student knows the skill
- `P(T)` — probability that learning occurs after practice
- `P(G)` — probability of guessing correctly without knowing
- `P(S)` — probability of slipping and answering incorrectly despite knowing

### B. Llama 3.3-70B-Instruct

Llama is the generative AI engine.

It is responsible for:

- Lesson summaries
- AI-generated quiz questions
- Explanations
- Hints
- Personalized feedback
- Learning recommendations
- AI tutoring/learning support

The model is accessed through an inference/API service as specified by the project.

### Critical distinction

**BKT estimates what the student knows.**

**Llama 3.3-70B-Instruct generates learning content and feedback based on the student's learning context and mastery information.**

Do NOT implement Llama as the mathematical mastery calculator.

---

## 3. Users

### Student

Students should be able to:

- Log in securely.
- Access assigned/enrolled Science subjects.
- View lessons and learning materials.
- Read AI-generated summaries.
- Take AI-generated quizzes.
- Receive adaptive questions.
- View quiz results.
- View competency mastery.
- Receive personalized feedback.
- Receive personalized learning recommendations.
- View learning progress.
- Use QuestAI/personalized AI learning support where implemented.

### Professor

Professors should be able to:

- Log in securely.
- Upload and organize Science learning materials.
- Manage subjects and lessons.
- View student progress.
- View competency mastery.
- View learning analytics.
- View dashboards and reports.
- Identify students who may require intervention.

**Important:** Professors do NOT manually create quizzes in the current system design. Quiz generation is performed by the AI.

### Administrator

Administrators should be able to:

- Manage users.
- Manage roles.
- Manage system settings.
- Monitor system activity.
- Manage appropriate administrative data.

---

## 4. Supported Learning Materials

The current scope supports:

- PDF documents
- Microsoft PowerPoint presentations

These instructional materials are the basis for AI-enabled learning content.

AI-generated educational content should be grounded in the uploaded materials whenever the task is explicitly based on them.

---

## 5. Intended AI Workflow

```text
Professor uploads PDF/PPT
        ↓
LearnQuest stores the learning material
        ↓
Extract instructional content
        ↓
Prepare relevant lesson/context data
        ↓
Llama 3.3-70B-Instruct
        ├── Lesson summary
        ├── Quiz questions
        ├── Explanations
        ├── Feedback
        └── Recommendations
        ↓
Student takes AI-generated quiz
        ↓
Student responses are recorded
        ↓
BKT processes correct/incorrect responses
        ↓
Competency mastery is updated
        ↓
Adaptive learning logic
        ├── Remedial material
        ├── Additional practice
        ├── Appropriate quiz difficulty
        ├── More challenging content
        └── Personalized recommendations
        ↓
Student continues learning
        ↓
New interaction data updates BKT
```

This should operate as a continuous feedback loop.

---

# 6. BKT Implementation

BKT mastery must be tracked **per competency**, not simply as an overall quiz percentage.

Example:

```text
Newton's Laws of Motion = 0.80
Energy and Momentum       = 0.40
Thermodynamics            = 0.65
```

A raw quiz score is different from BKT mastery.

For example:

```text
10/20 = 50% raw quiz score
```

does NOT automatically mean:

```text
BKT mastery = 50%
```

BKT considers the sequence of responses and its probabilistic parameters.

## Recommended Laravel location

```text
app/
└── Services/
    └── Bkt/
        └── BayesianKnowledgeTracingService.php
```

Keep all BKT calculations centralized.

Suggested responsibilities:

```text
initializeMastery()
updateAfterCorrectResponse()
updateAfterIncorrectResponse()
getMastery()
```

The service must be unit tested.

---

# 7. BKT Data Requirements

The system should preserve individual response data so mastery can be updated from the sequence of observations.

Suggested response data:

```text
id
student_id
quiz_attempt_id
question_id
competency_id
response
is_correct
answered_at
```

Suggested BKT record:

```text
id
student_id
competency_id
initial_mastery
current_mastery
p_l0
p_t
p_g
p_s
observations_count
correct_count
incorrect_count
last_response
last_updated_at
created_at
updated_at
```

The exact schema may be adapted to the existing Laravel database.

Do not store only aggregate quiz scores if sequential response data is required for BKT.

---

# 8. Adaptive Learning

BKT mastery should influence the student's next activity.

Conceptually:

```text
LOW MASTERY
→ Review lesson
→ Simpler targeted practice
→ Remediation

DEVELOPING MASTERY
→ Additional practice
→ Moderate difficulty
→ Targeted explanations

HIGH MASTERY
→ More challenging questions
→ Advanced practice
→ Next related competency
```

Thresholds should be configurable rather than duplicated throughout the codebase.

Do not claim that example thresholds are scientifically fixed unless they are formally defined by the research team.

---

# 9. Llama API Architecture

Do NOT call the Llama API directly from browser JavaScript.

Use:

```text
Browser
   ↓
Laravel API/Controller
   ↓
AI Service
   ↓
Inference / Proxies API
   ↓
Llama 3.3-70B-Instruct
```

API credentials must remain server-side.

Never expose API keys in:

- JavaScript
- Blade templates
- Public assets
- GitHub
- Browser requests

Use `.env`.

Suggested configuration:

```env
LLAMA_API_KEY=
LLAMA_API_URL=
LLAMA_MODEL=llama-3.3-70b-instruct
```

Add the configuration through Laravel's `config/services.php`.

Do not hard-code credentials.

---

# 10. Recommended Laravel AI Structure

Use services rather than putting AI logic into controllers.

```text
app/
├── Http/
│   ├── Controllers/
│   │   └── AI/
│   │       ├── QuizGenerationController.php
│   │       ├── SummaryController.php
│   │       ├── FeedbackController.php
│   │       └── RecommendationController.php
│   │
│   └── Requests/
│       └── AI/
│
├── Services/
│   ├── AI/
│   │   ├── LlamaService.php
│   │   ├── PromptService.php
│   │   ├── QuizGenerationService.php
│   │   ├── SummarizationService.php
│   │   ├── FeedbackService.php
│   │   └── RecommendationService.php
│   │
│   ├── Bkt/
│   │   └── BayesianKnowledgeTracingService.php
│   │
│   └── Learning/
│       └── AdaptiveLearningService.php
│
└── Models/
```

Controllers should coordinate requests and responses.

Business logic belongs in services.

---

# 11. LlamaService

The low-level Llama service should handle:

- API authentication
- HTTP requests
- Timeouts
- API errors
- Rate limits
- Response parsing
- Safe logging
- Retries where appropriate

Keep application-specific quiz logic out of the low-level API client.

---

# 12. Prompt Architecture

Do not place large prompts directly inside controllers.

Use a dedicated prompt layer such as:

```text
app/Services/AI/PromptService.php
```

or:

```text
resources/prompts/
├── quiz/
├── summary/
├── feedback/
└── recommendation/
```

Prompts should provide appropriate context, including:

- Subject
- Lesson
- Competency
- Relevant instructional content
- Student mastery when applicable
- Desired difficulty
- Number of questions
- Required output format

---

# 13. AI-Generated Quiz Requirements

Teachers do not create quizzes manually.

The system generates quizzes using Llama.

Relevant input should include:

```text
Subject
Lesson
Competency
Learning material content
Student mastery level
Desired difficulty
Number of questions
```

Prefer structured JSON output.

Conceptual format:

```json
{
  "questions": [
    {
      "question": "...",
      "choices": ["...", "...", "...", "..."],
      "correct_answer": "...",
      "explanation": "...",
      "competency": "...",
      "difficulty": "..."
    }
  ]
}
```

Validate the response before saving it.

Never blindly save arbitrary LLM output.

Validation should verify:

- Question exists
- Choices exist
- Correct answer exists
- Correct answer matches a choice
- Competency exists
- Difficulty is valid
- Explanation exists when required

---

# 14. Adaptive Quiz Generation

The adaptive process should be:

```text
Student mastery
      ↓
Adaptive Learning Service
      ↓
Determine appropriate difficulty/action
      ↓
Prepare Llama context
      ↓
Llama generates questions
      ↓
Validate output
      ↓
Save quiz/questions
```

Do not let the LLM independently calculate mastery.

BKT remains the mastery estimation mechanism.

---

# 15. Lesson Summarization

For uploaded PDF/PPT material:

1. Extract instructional content.
2. Preserve useful lesson structure.
3. Retrieve/provide relevant content to Llama.
4. Generate a student-appropriate summary.
5. Validate the result.
6. Store/display the summary.

For large documents, use chunking rather than sending an impractically large document in one request.

---

# 16. Document Processing

Keep document extraction separate from AI generation.

```text
Uploaded File
    ↓
Document Processor
    ├── PDF extraction
    └── PowerPoint extraction
    ↓
Normalized lesson text
    ↓
AI services
```

The extracted content should be associated with the learning material and lesson.

---

# 17. RAG / Grounding Direction

When AI output is based on uploaded materials, prioritize retrieval/grounding.

Conceptual workflow:

```text
Learning Material
       ↓
Extract text
       ↓
Chunk content
       ↓
Store searchable content
       ↓
Retrieve relevant lesson sections
       ↓
Provide context to Llama
       ↓
Generate summary/quiz/answer
```

Start with a maintainable retrieval architecture. Do not introduce an unnecessarily complicated vector database unless the actual requirements justify it.

The AI should not rely entirely on general model knowledge when the user explicitly asks for content based on a school's uploaded lesson.

---

# 18. Personalized Feedback

Feedback may include:

- Explanation of the concept
- Explanation of why an answer is incorrect
- Hints
- Suggested lesson review
- Encouragement
- Recommended practice

Useful context:

```text
Question
Student answer
Correct answer
Competency
Relevant lesson content
BKT mastery
```

Never expose system prompts, API keys, or internal credentials.

---

# 19. Personalized Recommendations

Recommendations should be based on actual learning data.

Possible inputs:

- Competency mastery
- Incorrect responses
- Quiz performance
- Lesson progress
- Completed materials
- Recent learning activity

Example:

```text
Low mastery in Energy Conservation
        ↓
Recommend relevant lesson
        ↓
Recommend targeted practice
        ↓
Generate adaptive questions
```

Do not base recommendations solely on arbitrary LLM output.

---

# 20. Learning Analytics

Record meaningful learning events, such as:

```text
lesson_viewed
material_opened
summary_viewed
quiz_started
question_answered
quiz_completed
recommendation_viewed
recommendation_completed
```

Potential dashboard information:

- Quiz performance
- Competency mastery
- Learning progress
- Completion rate
- Assessment activity
- Performance trends
- Areas of weakness
- Students requiring intervention

---

# 21. Radar Chart

The research design includes radar charts for multidimensional learning analytics.

The chart may visualize competency-related performance/mastery.

Example:

```text
Chemistry Competency A   80%
Chemistry Competency B   55%
Chemistry Competency C   72%
Chemistry Competency D   40%
```

The chart is a visualization only.

The database/BKT records remain the source of truth.

---

# 22. Database

Development database:

**MySQL**

Expected local configuration:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=learnquest_lms
DB_USERNAME=root
DB_PASSWORD=
```

Never commit `.env`.

`.env.example` should contain placeholders only.

---

# 23. Expected Core Tables

The exact schema may evolve, but the application is expected to need concepts such as:

```text
users
roles
subjects
lessons
learning_materials
competencies
quizzes
quiz_questions
quiz_attempts
quiz_answers
student_mastery
bkt_records
recommendations
ai_conversations
notifications
announcements
audit_logs
```

Only create tables that support actual implemented requirements.

---

# 24. Authentication and Authorization

Expected roles:

```text
admin
professor
student
```

Use Laravel authentication and authorization/middleware.

Do not rely only on frontend route hiding for security.

The backend must verify role and permissions.

Authentication may include:

- Login
- Registration
- Email verification
- Forgot password
- Password reset

Mail credentials must remain server-side.

---

# 25. Existing Frontend

An existing frontend is already being integrated into Laravel.

Historically it contains:

```text
frontend/
├── admin/
├── css/
├── html/
├── images/
├── js/
├── professor/
└── student/
```

It is being reorganized toward a reusable component-based architecture.

Do not discard or redesign the existing UI.

Before modifying it:

1. Inspect the existing implementation.
2. Preserve the UI.
3. Identify reusable components.
4. Avoid duplication.
5. Update references after moving files.
6. Migrate incrementally.

Reusable elements include:

- Navbar
- Sidebar
- PDF modal
- Cards
- Forms
- Notifications

---

# 26. Laravel Frontend Structure

Target structure:

```text
resources/
├── views/
│   ├── layouts/
│   ├── components/
│   ├── auth/
│   ├── admin/
│   ├── professor/
│   └── student/
│
├── css/
└── js/
```

Convert existing HTML into Blade incrementally.

Do not perform an unnecessary massive rewrite.

---

# 27. Example API Areas

Possible backend endpoints include:

```text
POST /api/ai/summarize
POST /api/ai/generate-quiz
POST /api/ai/feedback
POST /api/ai/recommendations

POST /api/quiz-attempts
POST /api/quiz-answers

GET /api/student/mastery
GET /api/student/recommendations
GET /api/student/progress

GET /api/professor/analytics
GET /api/professor/students
```

These are architectural examples. Follow the existing Laravel routing conventions.

---

# 28. Queues

AI generation and document processing may be slow.

Use Laravel queues when appropriate for:

- Large document processing
- Summary generation
- Large quiz generation
- Batch AI processing
- Analytics processing

Do not block ordinary page requests unnecessarily.

---

# 29. Error Handling

Handle:

- API timeout
- Invalid API response
- Rate limits
- Authentication errors
- Malformed JSON
- Empty generated content
- Unsupported documents
- File extraction errors

Show useful user-facing errors.

Do not expose raw stack traces or credentials.

Log technical details server-side.

---

# 30. AI Accuracy and Validation

AI outputs must be testable.

For quizzes, validate:

- Structure
- Required fields
- Correct-answer validity
- Competency mapping
- Difficulty
- Lesson relevance

For summaries:

- Non-empty output
- Reasonable length
- Relevant source context

For feedback:

- Correspondence to the question and response
- Relevance to the lesson
- No system secrets

The research paper discusses K-Fold Cross-Validation, User Acceptance Testing (UAT), and radar-chart-based learning analytics. Do not incorrectly apply K-Fold Cross-Validation to the Llama model simply because it appears in the paper; use it only where the implemented predictive/analytics component and evaluation design actually justify it.

---

# 31. Testing Strategy

## Unit Tests

Test:

- BKT calculations
- Adaptive difficulty selection
- AI response validation
- Recommendation rules

## Feature Tests

Test:

- Authentication
- Quiz submission
- Mastery updates
- AI endpoint authorization
- Role restrictions

## Integration Tests

Test:

```text
Laravel → Llama API
Laravel → MySQL
Quiz → BKT → Mastery
Mastery → Adaptive Quiz
```

## User Acceptance Testing

The research involves students and teachers evaluating the system, including usability, learning support, AI-generated feedback experience, and overall user experience.

---

# 32. Research Alignment

Implementation must remain aligned with the Statement of the Problem.

### System Development

Support:

- Learning material management
- AI-assisted document processing
- AI-generated quizzes
- Progress monitoring
- Personalized learning support

### AI Integration

Support:

- AI-generated Science quiz questions
- Personalized feedback
- Lesson summaries
- Adaptive learning based on performance

### Mastery Tracking and Learning Analytics

Support:

- BKT mastery estimation
- Competency gap identification
- Assessment/quiz performance analysis
- Adaptive learning pathways

### Monitoring and Decision Support

Support:

- Student progress
- Performance trends
- Dashboards
- Reports
- Identification of students requiring intervention
- Teacher decision support

### System Evaluation

The system is evaluated using the ISO/IEC 25010 characteristics specified in the capstone paper.

---

# 33. Scope Boundaries

Do not silently expand the project.

Current scope does NOT require:

- Teacher-created quizzes
- Mastery estimation by Llama
- Random Forest as the primary mastery algorithm
- DLKT as the primary mastery algorithm
- MDP as the primary mastery algorithm
- Replacing teacher expertise with AI

Current AI architecture:

```text
BKT + Llama 3.3-70B-Instruct
```

---

# 34. Security

Never commit:

```text
.env
API keys
SMTP passwords
database production passwords
tokens
private credentials
```

Use:

```text
.env
.env.example
```

Validate uploaded files.

Restrict uploads to supported formats.

Protect student performance and mastery data with authorization.

---

# 35. Development Rules for Claude

Before writing code:

1. Inspect the repository.
2. Understand the existing Laravel structure.
3. Check routes.
4. Check migrations/models.
5. Check authentication.
6. Check existing frontend components.
7. Reuse existing code where appropriate.
8. Avoid duplicate implementations.

When modifying code:

- Make the smallest safe change.
- Preserve existing functionality.
- Follow Laravel conventions.
- Keep controllers thin.
- Put business logic in services.
- Validate input.
- Use dependency injection where appropriate.
- Use configuration rather than hard-coded values.
- Write tests for important logic.
- Explain architectural changes briefly.

---

# 36. Common Implementation Mistakes to Avoid

### Do not expose the Llama API key

Wrong:

```javascript
const API_KEY = "...";
```

Correct:

```text
Browser
 → Laravel
 → Llama API
```

### Do not let Llama calculate mastery

Wrong:

```text
Llama says mastery = 80%
```

Correct:

```text
Student response
 → BKT
 → mastery probability
 → adaptive learning logic
 → Llama receives mastery context
```

### Do not equate quiz score with BKT mastery

Wrong:

```text
10/20 = 50% mastery
```

Correct:

```text
10/20 = raw assessment score
BKT = probabilistic competency mastery estimate
```

### Do not hard-code adaptive thresholds everywhere

Centralize configurable thresholds.

### Do not save unvalidated LLM output

Validate structured AI responses first.

### Do not put all AI logic in controllers

Use dedicated services.

### Do not rewrite the existing frontend unnecessarily

Preserve the current LearnQuest UI and migrate incrementally.

---

# 37. Recommended AI Implementation Order

## Phase 1 — AI Configuration

- Configure environment variables.
- Configure Laravel AI service.
- Implement secure API client.
- Test Llama connectivity.

## Phase 2 — Document Processing

- PDF extraction.
- PowerPoint extraction.
- Normalize extracted lesson content.
- Store extracted content.

## Phase 3 — Lesson Summaries

- Build prompt.
- Call Llama.
- Validate response.
- Save/display summary.

## Phase 4 — Competencies

- Define Science competencies.
- Associate lessons and questions with competencies.

## Phase 5 — Quiz Generation

- Build structured quiz prompt.
- Generate questions.
- Validate JSON.
- Save questions.
- Display quiz.

## Phase 6 — Quiz Attempts

- Record attempts.
- Record individual answers.
- Associate each question with a competency.

## Phase 7 — BKT

- Implement BKT service.
- Initialize mastery.
- Update mastery after each response.
- Store mastery history.
- Unit test formulas.

## Phase 8 — Adaptive Learning

- Determine learning action from mastery.
- Generate appropriate quiz difficulty.
- Recommend relevant materials.
- Connect BKT to Llama prompts.

## Phase 9 — Feedback

- Generate personalized explanations.
- Generate targeted feedback.
- Connect feedback to actual student responses.

## Phase 10 — Analytics

- Student mastery dashboard.
- Progress trends.
- Radar chart.
- Professor dashboard.
- Intervention indicators.

---

# 38. Definition of Done — AI Quiz Generation

Quiz generation is complete when:

- User is authorized.
- Correct lesson/material is selected.
- Relevant content is retrieved.
- BKT mastery is obtained when applicable.
- Difficulty is determined.
- Llama receives structured context.
- Llama returns structured output.
- Output is validated.
- Quiz/questions are stored safely.
- Competencies are assigned.
- Student can take the quiz.
- Responses are stored.
- BKT is updated.
- New mastery can influence future learning.

---

# 39. Definition of Done — BKT

BKT is complete when:

- Questions are mapped to competencies.
- Student responses are stored individually.
- BKT parameters are configurable.
- Initial mastery is initialized.
- Correct responses update mastery.
- Incorrect responses update mastery.
- Mastery is persisted.
- Mastery history can be inspected.
- Tests verify calculations.
- Adaptive learning can consume the resulting mastery.

---

# 40. Definition of Done — AI System

The AI subsystem is complete when this pipeline works:

```text
PDF/PPT
  ↓
Content extraction
  ↓
Lesson context
  ↓
Llama summary
  ↓
AI-generated quiz
  ↓
Student attempt
  ↓
Individual responses
  ↓
BKT mastery update
  ↓
Adaptive learning decision
  ↓
Llama personalized content/feedback
  ↓
Student continues learning
```

## Routeway API Integration

LearnQuest uses Routeway as the API provider for the Llama 3.3-70B-Instruct model. The application does not run the 70B model locally. Laravel communicates with Routeway through its OpenAI-compatible API.

### Environment Configuration

The Routeway credentials must be stored in Laravel's `.env` file and must never be exposed to frontend JavaScript.

```env
LLAMA_API_KEY=
LLAMA_API_URL=https://api.routeway.ai/v1
LLAMA_MODEL=llama-3.3-70b-instruct
```

The corresponding Laravel service configuration should be placed in `config/services.php`.

```php
'routeway' => [
    'api_key' => env('LLAMA_API_KEY'),
    'base_url' => env('LLAMA_API_URL', 'https://api.routeway.ai/v1'),
    'model' => env('LLAMA_MODEL', 'llama-3.3-70b-instruct'),
],
```

### Laravel Architecture

Routeway communication must be isolated inside:

```text
app/Services/AI/LlamaService.php
```

Other AI services should use `LlamaService` rather than communicating with Routeway directly.

```text
LlamaService
├── QuizGenerationService
├── SummarizationService
├── FeedbackService
└── RecommendationService
```

### Request Flow

```text
Laravel Controller
       ↓
AI Service
       ↓
LlamaService
       ↓
Routeway API
       ↓
Llama 3.3-70B-Instruct
       ↓
Routeway Response
       ↓
LlamaService
       ↓
AI Service
       ↓
Controller / Database / Frontend
```

### AI Responsibilities

Llama 3.3-70B-Instruct is responsible for generative AI functions, including:

- AI-generated quizzes
- Personalized explanations
- Personalized feedback
- Lesson summaries
- Hints and learning support
- Learning recommendations
- Adaptive question generation

Llama must not be used as the mathematical mastery-tracking algorithm.

### BKT Responsibility

Bayesian Knowledge Tracing is responsible for estimating student mastery of individual competencies.

```text
Student response
      ↓
BKT update
      ↓
Current competency mastery
      ↓
Adaptive learning decision
      ↓
Llama prompt
      ↓
Personalized AI content
```

The raw quiz percentage must not automatically be treated as the BKT mastery value.

### Security

- Never expose `LLAMA_API_KEY` in frontend JavaScript.
- Never hard-code the API key in controllers or services.
- Keep credentials in `.env`.
- Do not commit `.env` to GitHub.
- Validate AI-generated output before storing it in the database or displaying it to students.
- Handle Routeway API errors, timeouts, rate limits, and unavailable responses gracefully.

### Initial Integration Test

Before implementing the complete adaptive-learning pipeline, verify that Laravel can successfully send a simple chat-completion request through Routeway and receive a response from Llama 3.3-70B-Instruct.

The initial test should use a simple educational prompt before connecting document processing, quiz generation, BKT, or adaptive learning.

---

# 41. Final Instruction to Claude

Treat this file as the project's implementation specification and research context.

Before introducing a new AI technology, algorithm, database dependency, or architectural pattern, determine whether it is actually required by the current LearnQuest scope.

Prefer:

**simple + secure + testable + maintainable + research-aligned**

over unnecessary complexity.

Most importantly:

- Keep **BKT** responsible for mastery estimation.
- Keep **Llama 3.3-70B-Instruct** responsible for generative AI functions.
- Keep AI credentials server-side.
- Ground educational generation in uploaded learning materials.
- Store competency-level learning data.
- Make adaptive decisions traceable to student performance and mastery.
- Preserve the existing LearnQuest UI while integrating it with Laravel.
- Do not implement teacher-created quizzes unless the project requirements are explicitly changed.
