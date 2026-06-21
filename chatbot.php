<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'db_config.php';

// Handle AJAX requests for chat
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    $user_message = trim($_POST['message'] ?? '');
    
    if(empty($user_message)) {
        echo json_encode(['error' => 'Message is empty']);
        exit();
    }
    
    // Save user message
    $stmt = $pdo->prepare("INSERT INTO chat_logs (user_id, user_message, bot_response) VALUES (?, ?, '')");
    $stmt->execute([$_SESSION['user_id'], $user_message]);
    $message_id = $pdo->lastInsertId();
    
    // Get Gemini API response
    $bot_response = getGeminiResponse($user_message, $_SESSION['user_id'], $pdo);
    
    // Update bot response
    $stmt = $pdo->prepare("UPDATE chat_logs SET bot_response = ? WHERE id = ?");
    $stmt->execute([$bot_response, $message_id]);
    
    echo json_encode([
        'success' => true,
        'response' => $bot_response,
        'message_id' => $message_id
    ]);
    exit();
}

function getGeminiResponse($user_message, $user_id, $pdo) {
    $api_key = app_env('GEMINI_API_KEY');
    $today = date('Y-m-d');
    
    // Rich user context
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'pending') as pending_tasks,
            (SELECT COUNT(*) FROM tasks WHERE user_id = ? AND due_date < ? AND status = 'pending') as overdue_tasks,
            (SELECT COUNT(*) FROM study_plan WHERE user_id = ? AND plan_date = ? AND status = 'pending') as sessions_today,
            (SELECT MIN(due_date) FROM tasks WHERE user_id = ? AND status = 'pending' AND due_date >= ?) as nearest_due,
            (SELECT COUNT(*) FROM courses WHERE user_id = ?) as course_count,
            (SELECT total_study_hours FROM user_stats WHERE user_id = ?) as total_hours,
            (SELECT current_streak_days FROM user_stats WHERE user_id = ?) as streak_days
    ");
    $stmt->execute([$user_id, $user_id, $today, $user_id, $today, $user_id, $today, $user_id, $user_id, $user_id]);
    $context = $stmt->fetch();
    $context['today'] = $today;
    
    // Get upcoming tasks for richer context
    $stmt = $pdo->prepare("SELECT title, due_date, estimated_hours FROM tasks WHERE user_id = ? AND status = 'pending' ORDER BY due_date ASC LIMIT 3");
    $stmt->execute([$user_id]);
    $context['upcoming_tasks'] = $stmt->fetchAll();
    
    $system_prompt = "You are StudySync AI, a supportive study planning assistant for university students. 
Keep responses concise (2-4 sentences) unless the user asks for detail. Be encouraging and practical.
Tailor advice to the user's actual data below.

User's current data:
- Pending tasks: {$context['pending_tasks']}
- Overdue tasks: {$context['overdue_tasks']}
- Study sessions planned today: {$context['sessions_today']}
- Nearest deadline: {$context['nearest_due']}
- Courses enrolled: {$context['course_count']}
- Total study hours logged: {$context['total_hours']}
- Current streak: {$context['streak_days']} days

Guidelines:
- If overdue > 0, acknowledge it and suggest starting with the smallest task.
- If nearest_due is within 3 days, give urgency-aware advice.
- Reference their streak or total hours to encourage consistency.
- Suggest specific study techniques (Pomodoro, active recall, spaced repetition) when relevant.
- If they ask about the app, explain features concisely with actionable steps.";
    
    if(empty($api_key)) {
        return getFallbackResponse($user_message, $context);
    }

    $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.0-flash:generateContent?key=" . $api_key;
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $system_prompt . "\n\nUser question: " . $user_message]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 500,
            'topP' => 0.8,
            'topK' => 40
        ]
    ];
    
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data),
            'timeout' => 30
        ]
    ];
    
    $context_stream = stream_context_create($options);
    $response = @file_get_contents($url, false, $context_stream);
    
    if($response !== false) {
        $result = json_decode($response, true);
        if(isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return $result['candidates'][0]['content']['parts'][0]['text'];
        } elseif(isset($result['error'])) {
            error_log("Gemini API Error: " . json_encode($result['error']));
            return getFallbackResponse($user_message, $context);
        }
    }
    
    error_log("Gemini API request failed. Response: " . $response);
    return getFallbackResponse($user_message, $context);
}

function getFallbackResponse($message, $context) {
    $lower = strtolower($message);
    $pending = $context['pending_tasks'] ?? 0;
    $overdue = $context['overdue_tasks'] ?? 0;
    $sessions_today = $context['sessions_today'] ?? 0;
    $nearest_due = $context['nearest_due'] ?? null;
    $total_hours = $context['total_hours'] ?? 0;
    $streak = $context['streak_days'] ?? 0;

    // --- Study techniques ---
    if(strpos($lower, 'pomodoro') !== false) {
        return "The Pomodoro Technique: study for 25 minutes, take a 5-minute break. After 4 rounds, take a longer 15-30 minute break. Use the Focus Timer in StudySync to track Pomodoros automatically. It keeps you fresh and prevents burnout.";
    }

    if(strpos($lower, 'active recall') !== false || strpos($lower, 'retrieval practice') !== false) {
        return "Active recall means testing yourself instead of re-reading. After studying a topic, close the book and write down everything you remember. Use flashcards or practice questions. It's 2-3x more effective than passive review.";
    }

    if(strpos($lower, 'spaced repetition') !== false || strpos($lower, 'spacing') !== false || strpos($lower, 'forgetting curve') !== false) {
        return "Spaced repetition: review material at increasing intervals (1 day, 3 days, 1 week, 1 month). Your brain strengthens connections each time. Try reviewing yesterday's notes for 10 minutes before starting new material.";
    }

    if((strpos($lower, 'note') !== false || strpos($lower, 'notes') !== false) && (strpos($lower, 'take') !== false || strpos($lower, 'method') !== false || strpos($lower, 'cornell') !== false || strpos($lower, 'how') !== false || strpos($lower, 'tip') !== false)) {
        return "Try the Cornell Method: divide your page into 3 sections — a narrow left column for cues, a wide right column for notes, and a bottom section for a 2-3 sentence summary. It forces you to process and organize information actively.";
    }

    // --- General study tips (broad catch) ---
    $study_tips_keywords = ['study tip', 'how to study', 'better study', 'study technique', 'study method', 'effective study', 'study skill', 'study strategy', 'how should i study', 'study advice', 'revision tip', 'revision technique'];
    foreach($study_tips_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return "Here are top study techniques:\n• Pomodoro — 25 min focus, 5 min break (use Focus Timer in the app)\n• Active recall — test yourself, don't just re-read\n• Spaced repetition — review at increasing intervals\n• Interleaving — mix different topics in one session\n\nWhich one would you like to learn more about?";
        }
    }

    // --- Procrastination / focus / getting started ---
    $procrast_keywords = ['procrastinate', 'procrastination', 'lazy', "can't start", 'cannot start', 'distracted', 'distraction', 'focus', 'concentrate', 'concentration', 'get started', 'starting', 'hard to start', 'keep putting off', 'avoiding'];
    foreach($procrast_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return "Try the 5-minute rule: commit to just 5 minutes of study. Momentum usually takes over after that. Remove phone distractions and use the Focus Timer to create urgency. You have " . ($streak > 0 ? "a {$streak}-day streak to protect!" : "a chance to start a new streak today!") . " \xF0\x9F\x8E\xAF";
        }
    }

    // --- Exam / test preparation ---
    $exam_keywords = ['exam', 'exams', 'test', 'quiz', 'final', 'midterm', 'mid-term', 'assessment', 'examination', 'revision'];
    foreach($exam_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            $urgency = '';
            if($nearest_due && $nearest_due <= date('Y-m-d', strtotime('+3 days'))) {
                $urgency = " Your nearest deadline is " . date('M j', strtotime($nearest_due)) . " — focus on high-yield topics first.";
            }
            return "For exam success: break topics into small chunks, use active recall (close the book and recite), and spread review across multiple days.{$urgency} Set task types to 'exam' in StudySync so the schedule prioritizes them.";
        }
    }

    // --- Overwhelm / stress / burnout ---
    $stress_keywords = ['overwhelm', 'overwhelmed', 'stress', 'stressed', 'anxious', 'anxiety', 'burnout', 'burnt out', 'too much', 'can\'t cope', 'struggling', 'struggle', 'panic', 'panicking', 'worried', 'worry'];
    foreach($stress_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return "Take a deep breath. Focus on ONE thing at a time — not everything at once." . ($overdue > 0 ? " You have {$overdue} overdue tasks, but that's okay. Pick the smallest one and do it now." : "") . " Use your StudySync schedule to see what's next and trust the plan. Small steps compound into big progress.";
        }
    }

    // --- Motivation ---
    $motivation_keywords = ['motivation', 'motivate', 'motivated', 'unmotivated', 'lack of motivation', 'no motivation', 'can\'t be bothered', 'drained', 'tired of studying', 'demotivated'];
    foreach($motivation_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            $hours = $total_hours > 0 ? " You've already logged {$total_hours} study hours — that's real progress!" : "";
            return "Motivation follows action, not the other way around. Start with ONE tiny task (even 2 minutes). Once you begin, momentum builds naturally.{$hours} What's the single smallest thing you can do right now?";
        }
    }

    // --- General / off-topic knowledge questions ---
    $general_topics = [
        'books' => [
            'keywords' => ['book', 'books', 'read', 'reading', 'literature', 'textbook', 'novel', 'ebook', 'what to read', 'reading list'],
            'response' => "Here are some great books depending on your interest:\n• For CS students: 'Code' by Petzold, 'Clean Code' by Martin, 'The Pragmatic Programmer'\n• For study skills: 'Atomic Habits' by James Clear — tiny habits compound into big results\n• For deep focus: 'Deep Work' by Cal Newport\n• For problem-solving: 'Think Like a Programmer' by V. Anton Spraul\n• For general knowledge: 'A Brief History of Time' by Hawking, 'Sapiens' by Harari\n\nWhat area interests you — programming, productivity, science, or something else?"
        ],
        'sleep' => [
            'keywords' => ['sleep', 'rest', 'resting', 'tired', 'fatigue', 'insomnia', 'nap', 'napping', 'bedtime', 'wake up', 'early morning', 'night', 'sleepy', 'sleep schedule', 'circadian'],
            'response' => "Sleep is essential for memory consolidation and focus. Key tips:\n• Aim for 7-9 hours of sleep per night — especially before exams\n• Teenagers and young adults need 8-10 hours for optimal brain function\n• Keep a consistent sleep/wake schedule, even on weekends\n• Avoid screens 30-60 min before bed — blue light suppresses melatonin\n• The best study-rest balance: study in 90-minute blocks, then take a 15-20 min break or nap\n• Power naps (10-20 min) boost alertness without sleep inertia\n\nYour brain consolidates what you studied during sleep, so never sacrifice rest to cram — it's counterproductive."
        ],
        'nutrition' => [
            'keywords' => ['food', 'foods', 'eat', 'eating', 'diet', 'nutrition', 'brain food', 'brain health', 'meal', 'breakfast', 'lunch', 'dinner', 'snack', 'healthy', 'fuel', 'energy', 'hungry', 'vitamin', 'supplement', 'drink', 'water', 'hydration', 'caffeine', 'sugar'],
            'response' => "What you eat directly affects your focus and memory:\n• Brain foods: fatty fish (omega-3), blueberries, dark chocolate, nuts, eggs, avocados, leafy greens\n• Stay hydrated — even mild dehydration drops concentration by 20%\n• Eat protein-rich breakfast (eggs, yogurt) for sustained morning energy\n• Avoid heavy, sugary meals before study sessions — they cause energy crashes\n• Caffeine in moderation (green tea or coffee) can sharpen focus, but avoid after 4 PM to protect sleep\n• Complex carbs (oats, whole grains) provide steady energy for long study sessions\n\nThink of food as fuel for your brain — quality in = quality out."
        ],
        'exercise' => [
            'keywords' => ['exercise', 'workout', 'physical', 'fitness', 'gym', 'run', 'running', 'walk', 'walking', 'sport', 'yoga', 'stretch', 'stretching', 'active', 'movement', 'cardio'],
            'response' => "Exercise is one of the best things you can do for your brain:\n• 20-30 minutes of cardio (walking, jogging, cycling) boosts memory and focus for up to 2 hours afterward\n• Exercise increases BDNF — a protein that helps brain cells grow and connect\n• A quick walk between study sessions resets attention and reduces stress\n• Even 5 minutes of stretching or jumping jacks can break a study slump\n• Aim for 3-4 sessions per week — your brain will thank you on exam day\n\nMovement = better learning. Don't treat exercise as time away from studying — it's an investment in studying better."
        ],
        'memory' => [
            'keywords' => ['memory', 'memorize', 'memorization', 'remember', 'recall', 'forget', 'forgetting', 'mnemonic', 'flashcard', 'flash cards', 'anki', 'rote', 'cram', 'cramming', 'brain dump'],
            'response' => "Evidence-based memory techniques:\n• Active recall: close your book and recite from memory — single most effective technique\n• Spaced repetition: review at 1 day → 3 days → 1 week → 1 month (use Anki or flashcards)\n• Mnemonics: create acronyms, visual stories, or memory palaces for hard-to-remember lists\n• Chunking: break large information into groups of 3-5 items\n• Teach someone else: explaining a concept out loud forces your brain to organize it\n• Write it by hand: handwriting activates deeper processing than typing\n\nCramming works for short-term but fails for long-term retention. Space it out instead."
        ],
        'study_environment' => [
            'keywords' => ['environment', 'study space', 'desk', 'room', 'background noise', 'music', 'lofi', 'lo-fi', 'quiet', 'library', 'coffee shop', 'cafe', 'distraction', 'distractions', 'phone', 'setup', 'organize', 'declutter'],
            'response' => "Your study environment shapes your focus:\n• Dedicated study spot: train your brain to associate that space with focus (not bed!)\n• Keep your desk clean and minimal — clutter competes for attention\n• Background music: lo-fi, classical, or nature sounds can help — lyrics usually hurt concentration\n• Phone on silent and out of sight (not just face-down) — out of sight, out of mind\n• Good lighting: natural light is best; dim lighting strains eyes and causes drowsiness\n• Temperature: slightly cool (20-22°C / 68-72°F) is best for focus\n• Use website blockers (Cold Turkey, Freedom) during deep study sessions\n\nYour environment either works for you or against you. Set it up intentionally."
        ],
        'study_groups' => [
            'keywords' => ['study group', 'study buddy', 'group study', 'peer', 'classmate', 'collaborate', 'collaboration', 'partner', 'friends', 'team', 'group project', 'discussion'],
            'response' => "Study groups can be powerful if done right:\n• Keep groups small: 3-4 people max — larger groups lose focus\n• Assign each person a topic to teach to the rest (learning by teaching)\n• Set a clear agenda before each session — don't just 'meet up to study'\n• Use the first 5 min to set goals, the last 5 min to review what you covered\n• Solo study for initial learning, group study for review and problem-solving\n• Avoid turning study groups into social hours — or schedule social time after\n\nGood study groups hold you accountable and deepen understanding through discussion."
        ],
        'goal_setting' => [
            'keywords' => ['goal', 'goals', 'target', 'objective', 'aim', 'purpose', 'plan', 'planning', 'smart goal', 'achieve', 'accomplish', 'milestone', 'aspiration', 'ambition'],
            'response' => "Effective goal-setting for students:\n• Use SMART goals: Specific, Measurable, Achievable, Relevant, Time-bound\n• Break big goals (\"ace the exam\") into weekly micro-goals (\"complete 3 practice problems daily\")\n• Write goals down — you're 42% more likely to achieve them\n• Review your goals every Sunday and adjust for the week ahead\n• Track progress in StudySync — each task completed is a step toward your goal\n• Celebrate small wins to maintain momentum\n\nBig goals feel overwhelming. Break them down until each step takes less than 30 minutes."
        ],
        'productivity_tools' => [
            'keywords' => ['tool', 'tools', 'app', 'apps', 'software', 'website', 'extension', 'productivity app', 'notion', 'anki', 'obsidian', 'todoist', 'trello', 'notability', 'goodnotes', 'onenote', 'evernote', 'forest', 'focus app', 'website blocker'],
            'response' => "Useful tools for student productivity:\n• Note-taking: Notion (all-in-one), Obsidian (linked notes), OneNote (free & structured)\n• Flashcards: Anki (spaced repetition built-in) — free on desktop, paid on iOS\n• Focus: Forest app (grow trees while focusing), Pomodoro timers (built into StudySync!)\n• Task management: Todoist (simple), Trello (visual boards), or StudySync's built-in tasks\n• Writing: Grammarly for grammar, Google Docs for collaboration, Zotero for citations\n• Distraction blocking: Cold Turkey, Freedom, or LeechBlock browser extensions\n\nStick to 2-3 tools max — too many tools become a distraction themselves."
        ],
        'online_learning' => [
            'keywords' => ['online class', 'online course', 'zoom', 'virtual', 'remote learning', 'distance learning', 'mooc', 'coursera', 'udemy', 'edx', 'youtube tutorial', 'self-study', 'self study', 'learn online', 'e-learning', 'elearning'],
            'response' => "Tips for online learning success:\n• Treat online classes like in-person — show up on time, sit at a desk, not in bed\n• Use the '2-minute rule': write one key takeaway within 2 minutes of class ending\n• For self-study (Coursera, Udemy): set a fixed schedule just like a real class\n• Watch videos at 1.5x or 2x speed for review, normal speed for first-time learning\n• Take screen notes or use timestamped bookmarks to find key points later\n• Join discussion forums or Discord communities for accountability and help\n\nOnline learning requires more self-discipline. Create structure to replace the classroom environment."
        ],
        'presentations' => [
            'keywords' => ['presentation', 'present', 'speak', 'speech', 'public speaking', 'talk', 'slide', 'slides', 'powerpoint', 'oral', 'presenting', 'stage fright', 'nervous', 'audience'],
            'response' => "Tips for better presentations:\n• Structure: tell them what you'll say → say it → tell them what you said\n• Slides are for visuals, not scripts — use bullet points, not paragraphs\n• Practice out loud (not in your head) at least 3 times before the real thing\n• Record yourself on video once — you'll spot habits you didn't notice\n• Handle nerves: deep breath before starting, slow down, pause between points\n• Eye contact: look at one friendly face at a time, rotate every 5-10 seconds\n• If you go blank, say \"let me rephrase that\" — buys you thinking time\n\nThe goal is communication, not perfection. Your audience wants you to succeed."
        ],
        'cs_projects' => [
            'keywords' => ['project', 'projects', 'portfolio', 'build', 'github', 'side project', 'personal project', 'capstone', 'final year', 'project idea', 'what to build'],
            'response' => "Good CS projects build skills AND look great on your resume. Here are ideas by level:\n\nBeginners:\n• Personal portfolio website (HTML/CSS/JS)\n• To-do list app with local storage\n• Calculator with a GUI (Python Tkinter or JS)\n• Weather app using a public API\n• Rock-paper-scissors game\n\nIntermediate:\n• Blog platform with user auth (full-stack)\n• Real-time chat app (Socket.io)\n• Expense tracker with charts\n• URL shortener (like bit.ly)\n• E-commerce API with payment sandbox\n• Library management system (SQL + CRUD)\n\nAdvanced:\n• Task scheduler with calendar sync (like StudySync!)\n• Real-time collaboration editor (like Google Docs)\n• Image recognition classifier (CNN)\n• Web scraper + data analysis dashboard\n• Recommendation engine (collaborative filtering)\n• Compiler or interpreter for a simple language\n\nTip: pick one project and finish it 100% instead of starting five and finishing none. Deployment counts!"
        ],
        'algorithms' => [
            'keywords' => ['algorithm', 'algorithms', 'data structure', 'data structures', 'big o', 'time complexity', 'space complexity', 'sorting', 'searching', 'binary search', 'tree', 'graph', 'linked list', 'stack', 'queue', 'hash', 'dynamic programming', 'recursion', 'divide and conquer'],
            'response' => "Algorithms are the core of CS problem-solving. Key concepts to master:\n• Big O notation — learn to analyze time & space complexity (this is the #1 thing interviewers test)\n• Core data structures: arrays, linked lists, stacks, queues, hash tables, trees (BST, heaps), graphs\n• Sorting: understand how merge sort, quicksort, and counting sort work under the hood\n• Key techniques: recursion, two pointers, sliding window, BFS/DFS, dynamic programming, greedy\n• Practice strategy: solve 1-2 problems daily on LeetCode or HackerRank, starting with Easy difficulty\n• Study order: arrays → hash tables → recursion → trees → graphs → DP\n\nTextbook: 'Introduction to Algorithms' (CLRS) is the gold standard, but start with 'Grokking Algorithms' for intuition."
        ],
        'databases' => [
            'keywords' => ['database', 'databases', 'sql', 'mysql', 'nosql', 'postgresql', 'mongodb', 'query', 'schema', 'table', 'index', 'normalization', 'join', 'foreign key', 'primary key', 'transaction', 'acid', 'orm'],
            'response' => "Database fundamentals every CS student should know:\n• SQL basics: SELECT, JOIN (INNER, LEFT, RIGHT), GROUP BY, HAVING, subqueries — practice on LeetCode's database section\n• Normalization: 1NF (atomic values) → 2NF (no partial dependency) → 3NF (no transitive dependency) — understand the why, not just the rules\n• Indexes: how B-trees speed up lookups, and why too many indexes slow down writes\n• Transactions & ACID: Atomicity, Consistency, Isolation, Durability — critical for reliability\n• NoSQL: when to use document stores (MongoDB) vs key-value (Redis) vs graph (Neo4j)\n• Design tip: sketch your schema on paper before writing any CREATE TABLE statements\n\nProject idea: build a simple library management system from scratch — covers everything above."
        ],
        'web_development' => [
            'keywords' => ['web dev', 'web development', 'frontend', 'backend', 'full stack', 'full-stack', 'html', 'css', 'javascript', 'react', 'vue', 'angular', 'node', 'node.js', 'express', 'api', 'rest api', 'restful', 'http', 'client-server', 'dom', 'responsive'],
            'response' => "Web development is a huge field — here's how to navigate it:\n• Frontend: HTML (structure) → CSS (styling) → JavaScript (behavior). Master vanilla JS first before jumping to React\n• Backend: pick one language — Node.js (JavaScript), Python (Django/Flask), PHP (Laravel), or Java (Spring)\n• APIs: learn REST principles — GET/POST/PUT/DELETE, status codes, request/response structure\n• Database: pair your backend with a database (PostgreSQL or MySQL for relational, MongoDB for documents)\n• Full-stack project path: build a todo app → a blog → an e-commerce site → a real-time chat app\n• Must-know: how the browser renders a page (DOM, CSSOM, render tree), HTTP methods, cookies vs localStorage\n\nFree resources: The Odin Project, freeCodeCamp, MDN Web Docs. Build projects, don't just watch tutorials."
        ],
        'data_science' => [
            'keywords' => ['data science', 'data scientist', 'machine learning', 'ml', 'ai', 'artificial intelligence', 'deep learning', 'neural network', 'tensorflow', 'pytorch', 'scikit-learn', 'pandas', 'numpy', 'data analysis', 'data mining', 'statistics', 'regression', 'classification', 'clustering', 'dataset'],
            'response' => "Data science & ML path for CS/Math students:\n• Foundation: linear algebra, probability, statistics (Bayes, distributions, hypothesis testing)\n• Programming: Python — master pandas (data manipulation), numpy (numerical computing), matplotlib/seaborn (visualization)\n• ML fundamentals: supervised (linear regression, decision trees, SVMs, neural nets) vs unsupervised (k-means, PCA, DBSCAN)\n• Deep learning: start with a single perceptron, then build up to multi-layer networks — understand backpropagation\n• Project progression: titanic survival → house price prediction → image classification → NLP sentiment analysis\n• Tools: scikit-learn for classic ML, TensorFlow/PyTorch for deep learning, Jupyter notebooks for exploration\n• Mathematics needed: linear algebra (vectors, matrices, eigenvalues), calculus (gradients, chain rule), statistics (distributions, p-values)\n\nFree courses: Andrew Ng's ML course (Coursera), Kaggle competitions for practice."
        ],
        'programming' => [
            'keywords' => ['programming', 'coding', 'programming language', 'code', 'developer', 'software', 'python', 'javascript', 'java', 'c++', 'html', 'css', 'web dev', 'learn to code', 'computer science', 'computer student', 'computer scientist', 'computing', 'information technology', 'software engineering'],
            'response' => "Learning to code opens many doors. Here's a roadmap:\n• First language: Python — reads like English, huge ecosystem, great for beginners. JavaScript if web dev is your goal\n• CS fundamentals: data structures, algorithms, Big O notation, object-oriented programming — these transfer across all languages\n• Project-based learning: build something you care about. A calculator → a game → a web app → a portfolio project\n• Practice platforms: LeetCode (interviews), HackerRank (skill-building), Codewars (gamified), GitHub (portfolio)\n• Common paths:\n  - Web dev: HTML/CSS/JS → framework (React/Vue) → backend (Node/Python/PHP) → databases\n  - Mobile: Kotlin (Android) or Swift (iOS) or React Native (cross-platform)\n  - Data/ML: Python → pandas → scikit-learn → TensorFlow/PyTorch\n  - Systems: C/C++ → operating systems → networking → embedded\n\nTip: code for 30 min daily rather than 5 hours on weekends. Consistency beats intensity."
        ],
        'software_engineering' => [
            'keywords' => ['software engineering', 'software engineer', 'software design', 'design pattern', 'architecture', 'microservices', 'clean code', 'refactoring', 'testing', 'unit test', 'integration test', 'tdd', 'git', 'version control', 'ci/cd', 'devops', 'agile', 'scrum', 'code review'],
            'response' => "Software engineering is about writing code that others can maintain:\n• Clean code: meaningful names, small functions, no comments that repeat the code (the code should be self-documenting)\n• Design patterns: Singleton, Factory, Observer, Strategy — learn the problem each solves, not just the pattern\n• Testing: unit tests (isolated), integration tests (combined), end-to-end (full flow). TDD: write the test first, then the code\n• Git: commit often with clear messages, use branches for features, write meaningful PR descriptions\n• Code reviews: read others' code with curiosity, not judgment. Explain your reasoning in comments\n• Architecture patterns: MVC (web), microservices (scalable), event-driven (real-time), layered (enterprise)\n• DevOps basics: CI/CD pipelines (GitHub Actions), Docker containers, cloud deployment (AWS/Azure/GCP)\n\nBook: 'Clean Code' by Robert C. Martin and 'The Pragmatic Programmer' by Hunt & Thomas"
        ],
        'operating_systems' => [
            'keywords' => ['operating system', 'os', 'linux', 'kernel', 'process', 'thread', 'concurrency', 'parallelism', 'scheduling', 'memory management', 'virtual memory', 'paging', 'segmentation', 'file system', 'system call', 'deadlock', 'synchronization', 'mutex', 'semaphore'],
            'response' => "Operating Systems — a core CS subject. Key topics:\n• Processes vs threads: processes are isolated (own memory space), threads share memory within a process\n• CPU scheduling: FCFS, SJF, Round Robin, Priority — understand the trade-offs\n• Memory management: paging (fixed-size pages), segmentation (variable-sized segments), virtual memory (pages on disk = swap)\n• Concurrency problems: race conditions, deadlocks (4 necessary conditions), how mutexes and semaphores solve synchronization\n• File systems: inodes, directories, permissions, how data is laid out on disk\n• System calls: how user programs request OS services (read, write, fork, exec)\n• Linux basics every CS student should know: file permissions (chmod), processes (ps, top), pipes (|), grep, ssh\n\nProject: build a simple shell that can run commands, handle pipes, and manage background processes."
        ],
        'networking' => [
            'keywords' => ['networking', 'computer network', 'network', 'tcp/ip', 'tcp', 'udp', 'ip', 'http', 'https', 'dns', 'dhcp', 'router', 'switch', 'protocol', 'osi model', 'ethernet', 'packet', 'latency', 'bandwidth', 'socket'],
            'response' => "Computer networking fundamentals:\n• OSI model (7 layers) vs TCP/IP model (4 layers) — know what each layer does, especially application, transport, and network\n• TCP vs UDP: TCP is reliable (retransmits lost packets, ordered delivery), UDP is fast (streaming, gaming)\n• HTTP/HTTPS: request methods, status codes (200, 301, 401, 500), headers, cookies, how TLS encrypts traffic\n• DNS: how your browser finds the IP for a domain name (recursive resolver → root → TLD → authoritative)\n• Key concepts: packet switching, routing algorithms (distance vector vs link state), NAT, firewalls, load balancers\n• Latency vs bandwidth: latency is delay (ms), bandwidth is throughput (Mbps) — they're different problems\n• Socket programming: how two programs communicate over a network — fundamental for backend dev\n\nWireshark is a great tool to actually SEE packets flowing. Run it while visiting a website."
        ],
        'mathematics' => [
            'keywords' => ['math', 'mathematics', 'algebra', 'calculus', 'geometry', 'trigonometry', 'statistics', 'equation', 'formula', 'probability', 'linear algebra', 'discrete math', 'proof', 'theorem', 'matrix', 'vector', 'differentiation', 'integration'],
            'response' => "Math is the foundation of CS. Here's what matters and how to study it:\n• Discrete math: the most directly useful for CS — logic, sets, combinatorics, graph theory, proof techniques (induction, contradiction). This sharpens your algorithmic thinking\n• Linear algebra: vectors, matrices, eigenvalues — essential for machine learning, computer graphics, and 3D graphics\n• Calculus: differentiation (gradient descent in ML), integration (probability distributions), series/sequences (algorithm analysis)\n• Probability & statistics: Bayes theorem, distributions (normal, binomial), hypothesis testing — critical for data science\n• Study approach:\n  1. Understand the concept intuitively first (3Blue1Brown on YouTube is excellent)\n  2. Work through proofs to build rigor\n  3. Do problems daily — math is a skill, not a spectator sport\n  4. Connect math to CS: see linear algebra in ML, graph theory in networks, probability in algorithms\n\nRecommended: 'Mathematics for Computer Science' by Lehman, Leighton & Meyer (MIT OCW) — free and excellent."
        ],
        'science' => [
            'keywords' => ['science', 'biology', 'chemistry', 'physics', 'experiment', 'lab', 'scientific', 'research'],
            'response' => "For science subjects, focus on understanding concepts rather than memorizing facts. Draw diagrams, explain concepts out loud (Feynman technique), and connect new ideas to what you already know. Practice with past papers or questions to test your understanding. What science subject are you studying?"
        ],
        'language' => [
            'keywords' => ['language', 'learn english', 'learn spanish', 'learn french', 'vocabulary', 'grammar', 'foreign language', 'bilingual'],
            'response' => "The best way to learn a language is consistent daily exposure: 15-30 minutes of reading, listening, or speaking practice every day. Use flashcards (like Anki) for spaced repetition of vocabulary. Try to think in the language and practice with native speakers. What language are you learning?"
        ],
        'history' => [
            'keywords' => ['history', 'historical', 'geography', 'geopolitical', 'civilization', 'ancient', 'timeline'],
            'response' => "For history, create timelines to visualize events in order, and connect causes to effects. Use mnemonic devices for dates and names. Teaching the material to someone else (or even out loud to yourself) is one of the best ways to retain it. What period or topic are you studying?"
        ],
        'writing' => [
            'keywords' => ['write', 'writing', 'essay', 'creative writing', 'academic writing', 'grammar', 'composition', 'paper'],
            'response' => "Good writing comes from good structure: outline your main points first, write a rough draft without worrying about perfection, then revise for clarity and flow. Read your work aloud to catch awkward phrasing. Would you like tips on essays, creative writing, or academic papers?"
        ],
        'career' => [
            'keywords' => ['career', 'job', 'interview', 'resume', 'cv', 'internship', 'profession', 'work', 'employ', 'hire', 'hiring', 'portfolio', 'linkedin'],
            'response' => "For career prep, focus on building practical skills and a portfolio of work you can show. Tailor your resume to each role, practice common interview questions out loud, and network with people in your target field. Would you like specific advice on resumes, interviews, or skill-building?"
        ]
    ];

    foreach($general_topics as $topic) {
        foreach($topic['keywords'] as $kw) {
            if(strpos($lower, $kw) !== false) {
                return $topic['response'];
            }
        }
    }

    // --- Schedule / plan / timetable ---
    $schedule_keywords = ['schedule', 'scheduling', 'plan', 'planning', 'timetable', 'study plan', 'daily plan', 'weekly plan', 'organize', 'organisation', 'organization', 'routine'];
    foreach($schedule_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            if($sessions_today > 0) {
                return "You have {$sessions_today} study session(s) scheduled today on your dashboard. Each appears on the timeline with a Start button. Generate a fresh plan anytime if tasks change — it will reschedule everything into your available time slots.";
            }
            return "Go to your Dashboard and click 'Generate Plan' to create a schedule from your tasks, deadlines, and availability. First make sure you've set your weekly study hours in the Availability page so the plan fits your routine.";
        }
    }

    // --- Courses ---
    $course_keywords = ['course', 'courses', 'subject', 'subjects', 'class', 'classes', 'module', 'modules', 'unit', 'units'];
    foreach($course_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return "Add your courses in the Courses page with a color label. Then create tasks under each course. When you generate a study plan, tasks are organized by course and deadline. It keeps everything visible in one place. How many courses are you taking?";
        }
    }

    // --- App help / features / how to ---
    $app_keywords = ['how do i', 'how to', 'how can i', 'how would i', 'feature', 'features', 'what does', 'what is', 'help me', 'help', 'can you', 'how does', 'what can', 'usage', 'use studysync', 'studysync help', 'tutorial', 'guide', 'walkthrough', 'capabilities', 'what do you do', 'what are you'];
    foreach($app_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return "Here's what StudySync can do:\n• Dashboard — see today's schedule, upcoming tasks, and stats\n• Calendar — weekly view of all planned study blocks\n• Availability — set your recurring weekly study times\n• Focus Timer — Pomodoro sessions that auto-log study time\n• Courses — organize tasks by subject with colors\nWhich one would you like help with?";
        }
    }

    // --- Streak / progress / stats ---
    $streak_keywords = ['streak', 'progress', 'stats', 'statistics', 'how am i', 'how am i doing', 'performance', 'achievement', 'goal', 'goals', 'track', 'tracking', 'consistency', 'consistent'];
    foreach($streak_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return $streak > 0
                ? "You're on a {$streak}-day study streak! \xF0\x9F\x94\xA5 You've logged {$total_hours} total hours. Keep showing up daily — consistency beats intensity. Check your Dashboard for the full stats card."
                : "You haven't started a streak yet. Study today to begin one! Log your first session on the Dashboard or use the Focus Timer. Even 15 minutes counts toward your streak.";
        }
    }

    // --- Assignment / homework ---
    $assignment_keywords = ['assignment', 'assignments', 'homework', 'paper', 'essay', 'project', 'report', 'thesis', 'dissertation', 'lab report', 'write', 'writing'];
    foreach($assignment_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return "Break assignments into smaller steps: research -> outline -> draft -> revise -> final. Add each step as a separate task with its own deadline. StudySync will then schedule time for each piece, so you're not scrambling the night before.";
        }
    }

    // --- Time management ---
    $time_keywords = ['time management', 'manage time', 'manage my time', 'better use of time', 'efficient', 'productivity', 'productive', 'wasting time', 'time', 'deadline', 'deadlines', 'due date', 'due dates', 'prioritize', 'prioritise', 'priority', 'urgent'];
    foreach($time_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return "Key time management tips:\n1. Study in focused blocks (Pomodoro: 25 min work, 5 min break)\n2. Do the hardest task when you have most energy (morning for most)\n3. Set your weekly Availability in StudySync to protect study time\n4. Review what you accomplished at the end of each day";
        }
    }

    // --- Greeting / casual ---
    $greeting_keywords = ['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'sup', 'yo', 'howdy', 'greetings', 'what\'s up', 'wassup', 'how are you', 'how\'s it going', 'how do you do'];
    foreach($greeting_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            $time_of_day = (int)date('G') < 12 ? 'morning' : ((int)date('G') < 17 ? 'afternoon' : 'evening');
            return "Good {$time_of_day}! \xF0\x9F\x91\x8B I'm your StudySync study assistant." . ($streak > 0 ? " You're on a {$streak}-day streak — nice work!" : "") . " What would you like help with today? Studying tips, scheduling, motivation, or something else?";
        }
    }

    // --- Thanks ---
    $thanks_keywords = ['thank', 'thanks', 'thank you', 'thx', 'ty', 'appreciate', 'grateful'];
    foreach($thanks_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return "You're welcome! Keep up the great work. I'm here whenever you need study advice, motivation, or help navigating StudySync. \xF0\x9F\x93\x9A\xE2\x9C\xA8";
        }
    }

    // --- Suggest / recommend / advice (general) ---
    $suggest_keywords = ['suggest', 'suggestion', 'recommend', 'recommendation', 'what should i', 'advice', 'give me', 'tell me', 'any tips', 'some tips', 'ideas', 'what to do', 'what next', 'next step', 'where to start', 'what should i do'];
    foreach($suggest_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            if($overdue > 0) {
                return "I'd start with your {$overdue} overdue task(s) — tackling the smallest one first builds momentum. After that, check your Dashboard for today's scheduled sessions and knock them out one at a time. You've got this!";
            }
            if($nearest_due) {
                return "Your nearest deadline is " . date('M j', strtotime($nearest_due)) . ". I recommend breaking that task into smaller pieces and scheduling them in your Availability slots. Generate a study plan on the Dashboard to see it laid out.";
            }
            if($pending > 0) {
                return "You have {$pending} pending tasks. Here's what I'd do: open your Dashboard, look at the task with the earliest deadline, and start studying for just 15 minutes right now. Use the Focus Timer to make it official!";
            }
            return "Here's a tip: start with the smallest task you've been putting off — even 5 minutes builds momentum. Set your weekly Availability in StudySync, then generate a plan to see everything laid out. What subject are you focusing on?";
        }
    }

    // --- Ya / no / casual affirmations ---
    $affirm_keywords = ['yes', 'yeah', 'yep', 'sure', 'ok', 'okay', 'alright', 'cool', 'nice', 'great', 'awesome', 'sounds good', 'got it', 'i see', 'understood', 'makes sense', 'good', 'fine'];
    foreach($affirm_keywords as $kw) {
        if(trim($lower) === $kw || strpos($lower, $kw) !== false) {
            return "Glad that helps! Anything else you'd like to know? I can help with study techniques, scheduling, motivation, or using the app features.";
        }
    }

    // --- Context-driven if no keyword matched ---
    if($overdue > 0) {
        return "Heads up — you have {$overdue} overdue task(s). Don't stress! Pick the smallest one and start right now. Once you knock that out, the rest will feel less daunting. You can also adjust due dates in the Dashboard if needed. What subject is the most urgent?";
    }

    if($nearest_due && $nearest_due <= date('Y-m-d', strtotime('+2 days'))) {
        $days = (int)((strtotime($nearest_due) - strtotime($context['today'])) / 86400);
        return "Your nearest deadline is in {$days} day(s) — " . date('M j', strtotime($nearest_due)) . ". Make sure you've added all required tasks and generated a study plan. Focus on the most important topics first. Want some tips for last-minute prep?";
    }

    if($pending > 0) {
        return "You have {$pending} pending task(s)" . ($sessions_today > 0 ? " and {$sessions_today} study session(s) today" : "") . ". I'd suggest starting with the one due soonest — even just 15 minutes of focused work helps. Need advice on a specific task or topic?";
    }

    // Final fallback — graceful acknowledgment of limitation
    return "I'm designed as a study planning assistant, so that question is outside my area of expertise. But I can definitely help you with:\n\xE2\x80\xA2 Study techniques — Pomodoro, active recall, spaced repetition\n\xE2\x80\xA2 Exam prep and revision strategies\n\xE2\x80\xA2 Beating procrastination and staying motivated\n\xE2\x80\xA2 Time management and scheduling\n\xE2\x80\xA2 Using StudySync features\n\nWhat would you like help with?";
}

// Get chat history for display
$stmt = $pdo->prepare("SELECT * FROM chat_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$_SESSION['user_id']]);
$chat_history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Assistant | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="liquid-glass.css">
    <style>
        .chat-container {
            max-width: 900px;
            margin: 0 auto;
            height: calc(100vh - 140px);
            display: flex;
            flex-direction: column;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            overflow: hidden;
        }
        
        .chat-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-card);
        }
        
        .chat-header h1 {
            font-size: 20px;
            margin-bottom: 4px;
        }
        
        .chat-header p {
            font-size: 13px;
            color: var(--text-muted);
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .message {
            display: flex;
            gap: 12px;
            max-width: 80%;
            animation: fadeIn 0.3s ease;
        }
        
        .message.user {
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        
        .message-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .message.user .message-avatar {
            background: var(--accent);
        }
        
        .message.assistant .message-avatar {
            background: var(--accent);
        }
        
        .message-content {
            background: var(--bg-primary);
            padding: 12px 16px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .message.user .message-content {
            background: var(--accent);
            color: white;
        }
        
        .message-time {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        .chat-input-container {
            padding: 20px;
            border-top: 1px solid var(--border);
            background: var(--bg-card);
        }
        
        .chat-input-form {
            display: flex;
            gap: 12px;
        }
        
        .chat-input {
            flex: 1;
            background: var(--bg-primary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            color: var(--text-primary);
            font-size: 14px;
            resize: none;
            font-family: inherit;
        }
        
        .chat-input:focus {
            outline: none;
            border-color: var(--accent);
        }
        
        .send-btn {
            background: var(--accent);
            border: none;
            border-radius: 12px;
            padding: 0 20px;
            color: white;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .send-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
        }
        
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        
        .quick-btn {
            background: var(--bg-primary);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 12px;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quick-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }
        
        .typing-indicator {
            display: flex;
            gap: 4px;
            padding: 12px 16px;
            background: var(--bg-primary);
            border-radius: 16px;
            width: fit-content;
        }
        
        .typing-indicator span {
            width: 8px;
            height: 8px;
            background: var(--text-muted);
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }
        
        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
        
        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-8px); opacity: 1; }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>

        <main class="main-content" style="padding: 32px 80px;">
            <div class="chat-container">
                <div class="chat-header">
                    <h1>🤖 StudySync AI Assistant</h1>
                    <p>Powered by Google Gemini AI — Ask me anything about studying, time management, or using StudySync</p>
                </div>
                
                <div class="chat-messages" id="chatMessages">
                    <?php if(count($chat_history) == 0): ?>
                        <div class="message assistant">
                            <div class="message-avatar">🤖</div>
                            <div class="message-content">
                                Hello! I'm your StudySync AI assistant. I can help you with:<br><br>
                                • Study planning and time management<br>
                                • Exam preparation strategies<br>
                                • Beating procrastination<br>
                                • Using the StudySync app<br><br>
                                What would you like help with today?
                                <div class="message-time">Just now</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach(array_reverse($chat_history) as $msg): ?>
                            <div class="message user">
                                <div class="message-avatar">👤</div>
                                <div class="message-content">
                                    <?= htmlspecialchars($msg['user_message']) ?>
                                    <div class="message-time"><?= date('g:i A', strtotime($msg['created_at'])) ?></div>
                                </div>
                            </div>
                            <?php if($msg['bot_response']): ?>
                                <div class="message assistant">
                                    <div class="message-avatar">🤖</div>
                                    <div class="message-content">
                                        <?= nl2br(htmlspecialchars($msg['bot_response'])) ?>
                                        <div class="message-time"><?= date('g:i A', strtotime($msg['created_at'])) ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="chat-input-container">
                    <form id="chatForm" class="chat-input-form">
                        <textarea class="chat-input" id="messageInput" rows="1" placeholder="Ask me anything about studying, time management, or your schedule..." required></textarea>
                        <button type="submit" class="send-btn">Send</button>
                    </form>
                    <div class="quick-actions">
                        <button class="quick-btn" onclick="sendQuickMessage('How should I prepare for exams?')">📚 Exam prep</button>
                        <button class="quick-btn" onclick="sendQuickMessage('What is the Pomodoro technique?')">⏱️ Pomodoro</button>
                        <button class="quick-btn" onclick="sendQuickMessage('I keep procrastinating, help me!')">🎯 Get focused</button>
                        <button class="quick-btn" onclick="sendQuickMessage('How do I use StudySync?')">💡 App help</button>
                        <button class="quick-btn" onclick="sendQuickMessage('How can I improve my memory?')">🧠 Memory tips</button>
                        <button class="quick-btn" onclick="sendQuickMessage('I feel overwhelmed with assignments')">😌 Feeling overwhelmed</button>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        const chatMessages = document.getElementById('chatMessages');
        const chatForm = document.getElementById('chatForm');
        const messageInput = document.getElementById('messageInput');
        
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });
        
        function scrollToBottom() {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        scrollToBottom();
        
        function showTypingIndicator() {
            const typingDiv = document.createElement('div');
            typingDiv.className = 'message assistant';
            typingDiv.id = 'typingIndicator';
            typingDiv.innerHTML = `
                <div class="message-avatar">🤖</div>
                <div class="typing-indicator">
                    <span></span><span></span><span></span>
                </div>
            `;
            chatMessages.appendChild(typingDiv);
            scrollToBottom();
        }
        
        function removeTypingIndicator() {
            const indicator = document.getElementById('typingIndicator');
            if(indicator) indicator.remove();
        }
        
        function addMessage(sender, text, isUser = false) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isUser ? 'user' : 'assistant'}`;
            const now = new Date();
            const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            
            messageDiv.innerHTML = `
                <div class="message-avatar">${isUser ? '👤' : '🤖'}</div>
                <div class="message-content">
                    ${text.replace(/\n/g, '<br>')}
                    <div class="message-time">${timeStr}</div>
                </div>
            `;
            chatMessages.appendChild(messageDiv);
            scrollToBottom();
        }
        
        async function sendMessage(message) {
            if(!message.trim()) return;
            
            addMessage('user', message, true);
            messageInput.value = '';
            messageInput.style.height = 'auto';
            
            showTypingIndicator();
            
            try {
                const formData = new FormData();
                formData.append('message', message);
                
                const response = await fetch('chatbot.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                const data = await response.json();
                removeTypingIndicator();
                
                if(data.success && data.response) {
                    addMessage('assistant', data.response, false);
                } else if(data.error) {
                    addMessage('assistant', "Error: " + data.error, false);
                } else {
                    addMessage('assistant', "I'm having trouble connecting. Please try again in a moment.", false);
                }
            } catch(error) {
                console.error('Error:', error);
                removeTypingIndicator();
                addMessage('assistant', "Network error. Please check your connection and try again.", false);
            }
        }
        
        chatForm.addEventListener('submit', (e) => {
            e.preventDefault();
            sendMessage(messageInput.value);
        });
        
        function sendQuickMessage(message) {
            sendMessage(message);
        }
        
        messageInput.addEventListener('keydown', (e) => {
            if(e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage(messageInput.value);
            }
        });
        

    </script>
</body>
</html>
