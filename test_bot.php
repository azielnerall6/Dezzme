<?php
$python = 'python'; // or full path
$ask    = __DIR__ . '/chatbot/ask.py';
$cmd    = "$python " . escapeshellarg($ask) . " " . escapeshellarg("how do i upload a meme") . " 2>&1";
$out    = shell_exec($cmd);
echo "<pre>$out</pre>";
?>