<?php
// backend/pw_policy.php — shared password policy validator
// Policies: none, low, medium, high
// Return: ['ok'=>true] or ['ok'=>false, 'error'=>'message']
// Notes: For 'none' we only enforce non-empty password at the call site.
function validate_password_policy(string $password, string $username, string $policy): array {
  $u = mb_strtolower($username);
  $p = (string)$password;
  $policy = strtolower($policy ?: 'low');
  if ($policy === 'none') {
    return ['ok'=>true];
  }

  // Helper checks
  $hasLower = preg_match('/[a-z]/u', $p);
  $hasUpper = preg_match('/[A-Z]/u', $p);
  $hasDigit = preg_match('/\\d/', $p);
  $hasSymbol = preg_match('/[^\\p{L}\\p{N}]/u', $p) === 1; // anything not letter/digit
  $containsUser = (mb_stripos($p, $u) !== false);

  if ($policy === 'low') {
    if (strlen($p) < 8) return ['ok'=>false, 'error'=>'password must be at least 8 characters'];
    if (!$hasLower && !$hasUpper) return ['ok'=>false, 'error'=>'password must include at least one letter'];
    if ($containsUser) return ['ok'=>false, 'error'=>'password must not contain username'];
    return ['ok'=>true];
  }

  if ($policy === 'medium') {
    if (strlen($p) < 10) return ['ok'=>false, 'error'=>'password must be at least 10 characters'];
    $classes = ($hasLower?1:0)+($hasUpper?1:0)+($hasDigit?1:0)+($hasSymbol?1:0);
    if ($classes < 3) return ['ok'=>false, 'error'=>'use at least three of: lower, upper, digit, symbol'];
    if ($containsUser) return ['ok'=>false, 'error'=>'password must not contain username'];
    return ['ok'=>true];
  }

  // high
  if (strlen($p) < 12) return ['ok'=>false, 'error'=>'password must be at least 12 characters'];
  if (!($hasLower && $hasUpper && $hasDigit && $hasSymbol)) return ['ok'=>false, 'error'=>'include lower, upper, digit and symbol'];
  if ($containsUser) return ['ok'=>false, 'error'=>'password must not contain username'];
  // simple repetition check (e.g., aaa, 123123)
  if (preg_match('/(.)\\1{2,}/', $p)) return ['ok'=>false, 'error'=>'avoid obvious repeats'];
  // tiny common blacklist
  $black = ['password','letmein','qwerty','asdf1234','admin','changeme','12345678','welcome','iloveyou'];
  foreach ($black as $b) { if (strcasecmp($p, $b) === 0) return ['ok'=>false, 'error'=>'password too common']; }
  return ['ok'=>true];
}
