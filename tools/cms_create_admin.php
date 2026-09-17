<?php
declare(strict_types=1);
// Run privately over SSH, never deploy under public_html. No password arguments.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/admin/core.php';
try {
    $stdinMode = ($argv[1] ?? '') === '--stdin-json';
    if (!function_exists('stream_isatty')) throw new RuntimeException('Cannot check input channel.');
    if ($stdinMode) {
        // A trusted local helper prompts with echo disabled and sends JSON over
        // encrypted SSH stdin. Never use literal credentials in shell commands.
        if (stream_isatty(STDIN)) throw new RuntimeException('Private pipe required.');
    } elseif (!stream_isatty(STDIN) || PHP_OS_FAMILY==='Windows'
        || !function_exists('shell_exec') || !function_exists('exec')) {
        throw new RuntimeException('Use a private stdin helper.');
    }
    $db=cms_db();
    if ((int)$db->query('SELECT COUNT(*) FROM cms_users')->fetchColumn() !== 0) throw new RuntimeException('Initial account already exists.');
    if ($stdinMode) {
        $input=json_decode(stream_get_contents(STDIN,8192),true,8,JSON_THROW_ON_ERROR);
        foreach (['email','password','repeat'] as $field) if (!is_string($input[$field] ?? null)) throw new RuntimeException('Invalid input.');
        $email=strtolower(trim($input['email']));
        $password=$input['password']; $repeat=$input['repeat']; unset($input);
    } else {
        fwrite(STDOUT,'Admin email: '); $email=strtolower(trim((string)fgets(STDIN)));
    }
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) throw new RuntimeException('Invalid email.');
    if (!$stdinMode) {
    $state=shell_exec('stty -g');
    if (!$state) throw new RuntimeException('Cannot hide input.');
    exec('stty -echo',$out,$status); if ($status!==0) throw new RuntimeException('Cannot hide input.');
    try {
        fwrite(STDOUT,'Password (14-72 bytes): '); $password=rtrim((string)fgets(STDIN),"\r\n");
        fwrite(STDOUT,"\nRepeat password: "); $repeat=rtrim((string)fgets(STDIN),"\r\n");
    } finally { exec('stty '.escapeshellarg(trim($state))); fwrite(STDOUT,"\n"); }
    }
    if (!hash_equals($password,$repeat)) throw new RuntimeException('Passwords differ.');
    $hash=cms_hash($password); unset($password,$repeat);
    $db->prepare('INSERT INTO cms_users (email,password_hash) VALUES (?,?)')->execute([$email,$hash]);
    fwrite(STDOUT,"Administrator created.\n");
} catch (Throwable $e) { fwrite(STDERR,"Setup failed. Check private configuration, schema and input. No credentials were logged.\n"); exit(1); }
