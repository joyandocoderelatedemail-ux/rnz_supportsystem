<?php
// AI Summariser credentials (PHP 5.6 Compatible)
//
// THIS FILE IS GITIGNORED ON PURPOSE - it holds a live API key.
// Copy ai_config.sample.php to ai_config.php and paste your key below.
// Get one at https://openrouter.ai/keys

// Your OpenRouter key, the "sk-or-v1-..." string
define('OPENROUTER_API_KEY', '');

// The model to summarise with. Free tier, 1M context.
define('OPENROUTER_MODEL', 'nvidia/nemotron-3-ultra-550b-a55b:free');

// OpenAI compatible endpoint
define('OPENROUTER_URL', 'https://openrouter.ai/api/v1/chat/completions');

// Seconds to wait before giving up. A reasoning model on the free tier can be
// slow, so this is generous - the button shows a spinner meanwhile.
define('OPENROUTER_TIMEOUT', 60);
