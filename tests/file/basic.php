<?php

$config = Configuration::getInstance();
$config->override("repopath", $testWorkPath);
$config->override("user.default", "bees");
$config->override("user.default.teams", array(1, 2));
$config->override("auth_module", "single");
$config->override("keyfile", "$testWorkPath/test.key");
$config->override('modules.always', array("file"));

//do a quick authentication
$auth = AuthBackend::getInstance();
test_true($auth->authUser('bees','face'), "authentication failed");

//setup the required input keys
$input = Input::getInstance();
$input->setInput("team", 1);
$input->setInput("project", "monkies");

$output = Output::getInstance();

$mm = ModuleManager::getInstance();
$mm->importModules();
test_equal($mm->moduleExists("file"), true, "file module does not exist");

$file = $mm->getModule('file');

$repopath = $config->getConfig("repopath") . "/" . $input->getInput("team") . "/users/bees/" . $input->getInput("project");

$projectManager = ProjectManager::getInstance();
$projectManager->createRepository($input->getInput("team"), $input->getInput("project"));
$repo = $projectManager->getUserRepository(1, 'monkies', 'bees');
test_is_dir($repopath, "created repo did not exist");

section("Set file content");
$input->setInput('path', 'wut');
$input->setInput('data', 'deathcakes');
$file->dispatchCommand('put');
test_equal(file_get_contents("$repopath/wut"), 'deathcakes', 'wrong content in file');

section("Commit and test the result");
$repo->stage($input->getInput('path'));
$repo->commit('message', 'test-name', 'test@email.tld');
$repo->push();
$input->setInput('rev', 'HEAD');
$file->dispatchCommand('get');
test_equal($output->getOutput('original'), 'deathcakes', 'read unchanged original file incorrectly');

section("Autosave and test the result");
$input->setInput('data', 'bananas');
$file->dispatchCommand('put');
$file->dispatchCommand('get');
test_equal($output->getOutput('original'), 'deathcakes', 'read changed original file incorrectly');

section("Clear the autosave and test the result");
$input->setInput('files', array($input->getInput('path')));
$input->setInput('revision', 0);
$file->dispatchCommand('co');
$file->dispatchCommand('get');
test_equal($output->getOutput('original'), 'deathcakes', 'read checkouted original file incorrectly');

section("Copy the file and test the result");
$input->setInput('old-path', 'wut');
$input->setInput('new-path', 'huh');
$file->dispatchCommand('cp');
test_existent("$repopath/wut", 'old file was deleted during cp');
test_existent("$repopath/huh", 'new file not created during cp');
test_equal(file_get_contents("$repopath/huh"), 'deathcakes', 'new file had wrong content after cp');
// commit the result to clean the tree
$repo->stage('huh');
$repo->commit("bees","bees","bees@example.com");

section("Remove the original the file and test the result");
$input->setInput("files", array("wut"));
$file->dispatchCommand('del');
test_nonexistent("$repopath/wut", 'file not deleted during del');
// commit the result to clean the tree
$repo->commit("bees","bees","bees@example.com");

section("Move the copied file back onto the original and test the result");
$input->setInput('old-path', 'huh');
$input->setInput('new-path', 'wut');
$file->dispatchCommand('mv');
test_existent("$repopath/wut", 'target did not exist after move');
test_nonexistent("$repopath/huh", 'old file still exists after move');
// commit the result to clean the tree
$repo->stage('wut');
$repo->commit("bees","bees","bees@example.com");

section("Create a directory");
$input->setInput('path', 'spacey dir/wibble');
$file->dispatchCommand('mkdir');
test_is_dir("$repopath/spacey dir/wibble", 'after creation');
test_is_file("$repopath/spacey dir/wibble/.directory", 'after directory creation');
test_is_file("$repopath/spacey dir/.directory", 'after direct sub-directory creation');

// commit the result to clean the tree
$repo->stage('spacey dir/.directory');
$repo->stage('spacey dir/wibble/.directory');
$repo->commit("bees","bees","bees@example.com");

section("Remove a directory");
$input->setInput('files', array('spacey dir/wibble'));
$file->dispatchCommand('del');
test_is_dir("$repopath/spacey dir", 'parent directoty should remain after child removal');
test_nonexistent("$repopath/spacey dir/wibble", 'directoty should have been removed');

// commit the result to clean the tree
$repo->stage('spacey dir/wibble');
$repo->commit("bees","bees","bees@example.com");

section("Check the file listings");
$input->setInput('path', '.');
$file->dispatchCommand('list');
$expected = array('robot.py', 'spacey dir', 'wut');
test_equal($output->getOutput('files'), $expected, 'incorrect file list');
$file->dispatchCommand('compat-tree');
$expected = array(
    array('kind' => 'FILE',
          'name' => 'robot.py',
          'path' => '/monkies/robot.py',
          'children' => array(),
         ),
    array('kind' => 'FOLDER',
          'name' => 'spacey dir',
          'path' => '/monkies/spacey dir',
          'children' => array(),
         ),
    array('kind' => 'FILE',
          'name' => 'wut',
          'path' => '/monkies/wut',
          'children' => array(),
         ),
);
test_equal($output->getOutput('tree'), $expected, 'incorrect file tree');
