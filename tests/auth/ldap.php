<?php
$config = Configuration::getInstance();
$config->override('auth_module', 'ldap');
$config->override("keyfile", "$testWorkPath/test.key");
if (!$config->getConfig("ldap.host"))
	skip_test();
$config->override('ldap.host', 'localhost');

$input = Input::getInstance();
$input->setInput("user", "ide");
$input->setInput("password", $config->getConfig("ldap.ideuser.password"));

$auth = AuthBackend::getInstance();
test_nonnull($auth, "failed to get the auth backend");
test_class($auth, "LDAPAuth", "auth backend was not the ldap auth backend");

test_null($auth->getCurrentUserName(), "without authentication the user was not null");
test_true($auth->authUser($input->getInput("user"), $input->getInput("password")), "failed to auth user");
test_equal($auth->getCurrentUserName(), $input->getInput("user"), "the authed user was not the user passed to the auth module");

test_true($auth->authUser('IDE', $input->getInput("password")), "Failed to auth uppercased user");
test_equal($auth->getCurrentUserName(), $input->getInput("user"), "Should have normalised the user's casing.");

//TODO: check the users teams versus ldap

$token = $auth->getNextAuthToken();
$auth->deauthUser($token);
test_null($auth->getCurrentUserName(), "after deauth, the user was not null");
