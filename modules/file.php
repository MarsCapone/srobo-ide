<?php

class FileModule extends Module
{
	private $team;
	private $projectName;

	public function __construct()
	{
		$auth = AuthBackend::getInstance();

		// bail if we aren't authenticated
		if ($auth->getCurrentUserName() == null)
		{
			// module does nothing if no authentication
			return;
		}

		$this->installCommand('compat-tree', array($this, 'getFileTreeCompat'));
		$this->installCommand('list', array($this, 'listFiles'));
		$this->installCommand('get', array($this, 'getFile'));
		$this->installCommand('put', array($this, 'putFile'));
		$this->installCommand('del', array($this, 'deleteFile'));
		$this->installCommand('cp', array($this, 'copyFile'));
		$this->installCommand('mv', array($this, 'moveFile'));
		$this->installCommand('log', array($this, 'fileLog'));
		$this->installCommand('lint', array($this, 'lintFile'));
		$this->installCommand('diff', array($this, 'diff'));
		$this->installCommand('mkdir', array($this, 'makeDirectory'));
		$this->installCommand('co', array($this, 'checkoutFile'));
	}

	protected function initModule()
	{
		$this->projectManager = ProjectManager::getInstance();

		$input = Input::getInstance();
		$this->team = $input->getInput('team');

		// check that the project exists and is a git repo otherwise construct
		// the project directory and git init it
		$project = $input->getInput('project');

		$this->projectName = $project;
	}

	/**
	 * Ensures that the user is in the team they claim to be
	 */
	private function verifyTeam()
	{
		$auth = AuthBackend::getInstance();
		if (!in_array($this->team, $auth->getCurrentUserTeams()))
		{
			throw new Exception('proj attempted on team you aren\'t in', E_PERM_DENIED);
		}
	}

	/**
	 * Gets a handle on the repository for the current project
	 */
	private function repository()
	{
		$pm = ProjectManager::getInstance();
		$this->verifyTeam();
		$userName = AuthBackend::getInstance()->getCurrentUserName();
		$repo = $pm->getUserRepository($this->team, $this->projectName, $userName);
		$pm->updateRepository($repo, $userName);
		return $repo;
	}

	/**
	 * Gets a handle on the repository for the current project
	 */
	private function masterRepository()
	{
		$pm = ProjectManager::getInstance();
		$this->verifyTeam();
		$userName = AuthBackend::getInstance()->getCurrentUserName();
		$repo = $pm->getMasterRepository($this->team, $this->projectName);
		return $repo;
	}

	/**
	 * Makes a directory in the repository
	 */
	public function makeDirectory()
	{
		AuthBackend::ensureWrite($this->team);

		$input = Input::getInstance();
		$output = Output::getInstance();

		$path = $input->getInput("path");

		$repo = $this->repository();
		$success = $repo->gitMKDir($path);

		$paths = array();
		if ($success)
		{
			$now = time();
			do
			{
				$placeholder_file = $path . '/.directory';
				$paths[] = $placeholder_file;
				$success = $repo->touchFile($placeholder_file, $now);
			}
			while (($path = dirname($path)) != '.');

			if ($success)
			{
				$output->setOutput('paths', $paths);
			}
		}
		return $success;
	}

	/**
	 * Gets a recursive file tree, optionally at a specific revision
	 */
	public function getFileTreeCompat()
	{
		$input  = Input::getInstance();
		$output = Output::getInstance();

		$revision = $input->getInput('rev', true);

		$repo = $this->masterRepository();

		$uncleanOut = $repo->fileTreeCompat($this->projectName, $revision);
		$results = $this->sanitiseFileList($uncleanOut);
		$output->setOutput('tree', $results);
		return true;
	}

	/**
	 * Removes unwanted files from the given array.
	 * Previously, this was used to hide __init__.py, but this file is now shown.
	 */
	private function sanitiseFileList($unclean)
	{
		return array_values($unclean);
	}

	/**
	 * Check out a particular revision of a file.
	 * Also used to revert a file to its unmodified state.
	 */
	public function checkoutFile()
	{
		$input = Input::getInstance();
		$output = Output::getInstance();
		$paths = $input->getInput("files");
		$revision = $input->getInput("revision");
		//latest
		$output->setOutput("rev", $revision);
		$repo = $this->repository();
		if ($revision === 0 || $revision === "HEAD")
		{
			foreach ($paths as $file)
			{
				$repo->checkoutFile($file);
			}
		}
		else
		{
			$output->setOutput("revision reverting","");
			foreach ($paths as $file)
			{
				$repo->checkoutFile($file, $revision);
			}
		}

		$output->setOutput("success",true);
		return true;
	}

	/**
	 * Get a flat list of files in a specific folder
	 */
	public function listFiles()
	{
		$input  = Input::getInstance();
		$output = Output::getInstance();
		$path   = $input->getInput('path');
		$uncleanOut = $this->repository()->listFiles($path);
		$results = $this->sanitiseFileList($uncleanOut);
		$output->setOutput('files', $results);
		return true;
	}

	/**
	 * Get the contents of a given file in the repository
	 */
	public function getFile()
	{
		$input  = Input::getInstance();
		$output = Output::getInstance();
		$path   = $input->getInput('path');
		$revision = $input->getInput('rev');

		// The data the repo has stored
		$repo = $this->masterRepository();
		$original = $repo->getFile($path, $revision);

		$output->setOutput('original', $original);
		return true;
	}

	/**
	 * Save a file, without committing it
	 */
	public function putFile()
	{
		AuthBackend::ensureWrite($this->team);

		$input  = Input::getInstance();
		$path   = $input->getInput('path');
		$data   = $input->getInput('data');
		return $this->repository()->putFile($path, $data);
	}

	/**
	 * Delete a given file in the repository
	 */
	public function deleteFile()
	{
		AuthBackend::ensureWrite($this->team);

		$input  = Input::getInstance();
		$output = Output::getInstance();
		$files = $input->getInput("files");

		$repo = $this->repository();
		foreach ($files as $file)
		{
			$repo->removeFile($file);
		}
		return true;
	}

	/**
	 * Copy a given file in the repository
	 */
	public function copyFile()
	{
		AuthBackend::ensureWrite($this->team);

		$input   = Input::getInstance();
		$output  = Output::getInstance();
		$oldPath = $input->getInput('old-path');
		$newPath = $input->getInput('new-path');
		$this->repository()->copyFile($oldPath, $newPath);
		$output->setOutput('status', 0);
		$output->setOutput('message', $oldPath.' to '.$newPath);
		return true;
	}

	/**
	 * Move a given file in the repository
	 */
	public function moveFile()
	{
		AuthBackend::ensureWrite($this->team);

		$input   = Input::getInstance();
		$output  = Output::getInstance();
		$oldPath = $input->getInput('old-path');
		$newPath = $input->getInput('new-path');
		$this->repository()->moveFile($oldPath, $newPath);
		$output->setOutput('status', 0);
		$output->setOutput('message', $oldPath.' to '.$newPath);
		return true;
	}

	/**
	 * Get the log for a file.
	 * It expects a file to restrict the log to, and, optionally, an offset to start from and the number of entries wanted.
	 * It returns the requested entries and a list of authors that have committed to the file.
	 */
	public function fileLog()
	{
		$output = Output::getInstance();
		$input = Input::getInstance();
		$path = $input->getInput('path');

		$repo = $this->masterRepository();

		$number = $input->getInput('number', true);
		$offset = $input->getInput('offset', true);

		$number = ($number != null ? $number : 10);
		$offset = ($offset != null ? $offset * $number : 0);

		$log = $repo->log(null, null, $path);

		// if user has been passed we need to filter by author
		$user = $input->getInput("user", true);
		print $user;

		//take a backup of the log so we can list all the authors
		$originalLog = $log;

		//check if we've got a user and filter
		if ($user != null)
		{
			$filteredRevs = array();
			foreach ($log as $rev)
			{
				if ($rev["author"] == $user) $filteredRevs[] = $rev;
			}

			$log = $filteredRevs;
		}

		$output->setOutput('log', array_slice($log, $offset, $number));
		$output->setOutput('pages', ceil(count($log) / $number));

		$authors = array();
		foreach($originalLog as $rev)
		{
			$authors[] = $rev['author'];
		}

		$output->setOutput('authors', array_values(array_unique($authors)));

		return true;
	}

	/**
	 * Gets the diff of:
	 *  A log change
	 *  The current state of a file against the tree
	 */
	public function diff()
	{
		$output = Output::getInstance();
		$input = Input::getInstance();

		$hash = $input->getInput('hash');
		$path = $input->getInput('path');
		$newCode = $input->getInput('code', true);

		// patch from log
		if ($newCode === null)
		{
			$repo = $this->masterRepository();
			$diff = $repo->historyDiff($hash);
		}
		// diff against changed file
		else
		{
			$repo = $this->repository();
			$repo->putFile($path, $newCode);
			$diff = $repo->diff($path);
		}

		$output->setOutput("diff", $diff);
		return true;
	}

	/**
	 * Checks a given file for errors
	 */
	public function lintFile()
	{
		$input  = Input::getInstance();
		$output = Output::getInstance();
		$config = Configuration::getInstance();
		$path   = $input->getInput('path');

		$this->verifyTeam();
		$userName = AuthBackend::getInstance()->getCurrentUserName();

		$pm = ProjectManager::getInstance();
		$masterRepoPath = $pm->getMasterRepoPath($this->team, $this->projectName);

		$helper = new LintHelper($masterRepoPath, $this->projectName);

		$newCode = $input->getInput('code', true);
		$revision = $input->getInput('rev', true);

		try
		{
			$errors = $helper->lintFile($path, $revision, $newCode);
			if ($errors === false)
			{
				// something went badly wrong
				return false;
			}
		}
		catch (Exception $e)
		{
			$output->setOutput('error', $e->getMessage());
			return false;
		}

		// Sort & convert to jsonables if needed.
		// This (latter) step necessary currently since JSONSerializeable doesn't exist yet.
		if (count($errors) > 0)
		{
			usort($errors, function($a, $b) {
					if ($a->lineNumber == $b->lineNumber) return 0;
					return $a->lineNumber > $b->lineNumber ? 1 : -1;
				});
			$errors = array_map(function($lm) { return $lm->toJSONable(); }, $errors);
		}

		$output->setOutput("errors", $errors);
		return true;
	}
}
