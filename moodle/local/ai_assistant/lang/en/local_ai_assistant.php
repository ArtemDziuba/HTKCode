<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']   = 'AI Course Assistant';

// Settings
$string['setting_geminikey']      = 'Gemini API Key';
$string['setting_geminikey_desc'] = 'Your Google Gemini API key. Get one at https://aistudio.google.com/app/apikey';

// Page
$string['createcourse']          = 'Create a New Course';
$string['choosecreationmethod']  = 'How would you like to set up your course?';

// Cards
$string['manualcreation']       = 'Create Manually';
$string['manualcreation_desc']  = 'Use the standard Moodle course editor to fill in all details yourself.';
$string['aicreation']           = 'AI Assistance';
$string['aicreation_desc']      = 'Generate a course outline, quiz questions, or assignments using AI.';

// Form
$string['generatecontent']      = 'Generate Course Content';
$string['task']                 = 'What do you want to generate?';
$string['task_outline']         = 'Course structure (4-week outline)';
$string['task_quiz']            = 'Quiz questions (Moodle GIFT format)';
$string['task_assignment']      = 'Assignment with grading criteria';
$string['task_rewrite']         = 'Rewrite / improve uploaded document';

// File upload
$string['upload_file']          = 'Upload existing document';
$string['upload_file_optional'] = '(optional)';
$string['upload_hint']          = 'Drag & drop a file here or click to browse';
$string['or']                   = 'or type a prompt below';

// Prompt
$string['prompt']               = 'Describe your course or topic';
$string['prompt_optional']      = '(optional if file uploaded)';
$string['prompt_placeholder']   = 'e.g. Introduction to machine learning for undergraduate students…';

// Actions
$string['generate']             = 'Generate';
$string['generating']           = 'Generating… this may take a few seconds';
$string['result']               = 'Generated content';
$string['copy']                 = 'Copy';
$string['copied']               = 'Copied!';

// Errors
$string['noapikey'] = 'Gemini API key is not configured. Please ask your Moodle administrator to set it in Site Administration → Plugins → AI Course Assistant.';