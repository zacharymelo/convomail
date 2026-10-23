<?php
// Test token introspection for Dovecot: one valid token
$token = $_POST['token'] ?? '';
header('Content-Type: application/json');
echo json_encode($token === 'convomail-test-access-token' ? array('active' => true, 'email' => 'oauthuser@x.test') : array('active' => false));
