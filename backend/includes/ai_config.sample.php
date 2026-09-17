<?php
// AI Summariser credentials (PHP 5.6 Compatible)
//
// THIS IS THE TEMPLATE, and the only one of the pair that git tracks.
// Copy it to ai_config.php on every server and put the real key there:
//
//     cp backend/includes/ai_config.sample.php backend/includes/ai_config.php
//
// ai_config.php is gitignored on purpose because it holds a live API key. That
// also means a git deploy never carries it - a server without its own copy
// falls back to this template, the key is blank, and the Summarize with AI
// button reports that the summariser is not configured.

// Preferred: set ATRIA_API_KEY in the server environment (cPanel environment
// variables, an Apache SetEnv, or the shell) so the key never touches a file.
// Otherwise paste it as the fallback below, in ai_config.php only.
$ai_env_key = getenv('ATRIA_API_KEY');
define('AI_API_KEY', $ai_env_key ? $ai_env_key : '');

// The model that drafts the note.
define('AI_MODEL', 'Atria-Dawn-Preview');

// OpenAI compatible chat completions endpoint.
define('AI_URL', 'https://api.atria-asi.ai/v1/chat/completions');

// Seconds to wait before giving up on one attempt. The model reasons before it
// answers, so a summary usually lands in 3-10s; this leaves plenty of room.
define('AI_TIMEOUT', 60);
