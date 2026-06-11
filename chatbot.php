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
            return "Try the 5-minute rule: commit to just 5 minutes of study. Momentum usually takes over after that. Remove phone distractions and use the Focus Timer to create urgency. You have " . ($streak > 0 ? "a {$streak}-day streak to protect!" : "a chance to start a new streak today!") . " 🎯";
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
            return "Take a deep breath 🧘 Focus on ONE thing at a time — not everything at once." . ($overdue > 0 ? " You have {$overdue} overdue tasks, but that's okay. Pick the smallest one and do it now." : "") . " Use your StudySync schedule to see what's next and trust the plan. Small steps compound into big progress.";
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
                ? "You're on a {$streak}-day study streak! 🔥 You've logged {$total_hours} total hours. Keep showing up daily — consistency beats intensity. Check your Dashboard for the full stats card."
                : "You haven't started a streak yet. Study today to begin one! Log your first session on the Dashboard or use the Focus Timer. Even 15 minutes counts toward your streak.";
        }
    }

    // --- Assignment / homework ---
    $assignment_keywords = ['assignment', 'assignments', 'homework', 'paper', 'essay', 'project', 'report', 'thesis', 'dissertation', 'lab report', 'write', 'writing'];
    foreach($assignment_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return "Break assignments into smaller steps: research → outline → draft → revise → final. Add each step as a separate task with its own deadline. StudySync will then schedule time for each piece, so you're not scrambling the night before.";
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
            return "Good {$time_of_day}! 👋 I'm your StudySync study assistant." . ($streak > 0 ? " You're on a {$streak}-day streak — nice work!" : "") . " What would you like help with today? Studying tips, scheduling, motivation, or something else?";
        }
    }

    // --- Thanks ---
    $thanks_keywords = ['thank', 'thanks', 'thank you', 'thx', 'ty', 'appreciate', 'grateful'];
    foreach($thanks_keywords as $kw) {
        if(strpos($lower, $kw) !== false) {
            return "You're welcome! Keep up the great work. I'm here whenever you need study advice, motivation, or help navigating StudySync. 📚✨";
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

    // Final fallback — always asks a question to engage
    return "I'm your StudySync assistant! Ask me about:\n• Study techniques — Pomodoro, active recall, spaced repetition\n• Exam prep & revision strategies\n• Beating procrastination & staying motivated\n• Time management & scheduling\n• Using StudySync features\n\nWhat would you like help with? 📚";
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
