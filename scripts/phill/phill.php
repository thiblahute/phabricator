#!/usr/bin/env php
<?php

class LogLevel
{
  const EMERGENCY = 1;
  const ALERT     = 2;
  const CRITICAL  = 3;
  const ERROR     = 4;
  const WARNING   = 5;
  const NOTICE    = 6;
  const INFO      = 7;
  const DEBUG     = 8;
}
$logLevelThreshold = LogLevel::WARNING;

function logmsg($level, $pattern /* ... */) {
  global $logLevelThreshold;
  if ($level > $logLevelThreshold)
    return;
  $console = PhutilConsole::getConsole();
  $argv = func_get_args();
  array_shift($argv);
  array_unshift($argv, "%s\n");
  call_user_func_array(array($console, 'writeOut'), $argv);
}

function debug($pattern /* ... */) {
  $argv = func_get_args();
  array_unshift($argv, LogLevel::DEBUG);
  call_user_func_array('logmsg', $argv);
}

function warning($pattern /* ... */) {
  $argv = func_get_args();
  array_unshift($argv, LogLevel::WARNING);
  call_user_func_array('logmsg', $argv);
}

function notice($pattern /* ... */) {
  $argv = func_get_args();
  array_unshift($argv, LogLevel::NOTICE);
  call_user_func_array('logmsg', $argv);
}

function error($pattern /* ... */) {
  $argv = func_get_args();
  array_unshift($argv, LogLevel::ERROR);
  call_user_func_array('logmsg', $argv);
  exit(1);
}

class PhillTransaction
{
  public $date;
  public $transaction;
  public $task;
  public $user;
  public $idx;

  function PhillTransaction($date, $transaction, $user, $task, $idx) {
    $this->date = $date;
    $this->transaction = $transaction;
    $this->user = $user;
    $this->task = $task;
    $this->idx = $idx;
  }
}

class PhillImporter
{
  protected $json = null;
  protected $jsonDir = null;
  protected $commitLevel = "global";
  protected $projects = array();
  protected $tasks = array();

  public function load_json($filename)
  {
    $this->jsonDir = realpath(dirname($filename));
    $data = file_get_contents($filename);
    $this->json = json_decode($data);
    if (json_last_error() != JSON_ERROR_NONE)
      throw new Exception("decoding json from '$filename' failed: " . json_last_error_msg());
    return $this->json;
  }

  public function set_transaction_level($val)
  {
    switch ($val) {
      case "global":
      case "item":
      case "rollback":
        $this->commitLevel = $val;
        break;
      default:
        throw new Exception("unknown transaction level '$val': valid ones are 'global', 'item' and 'rollback'.");
    }
  }

  protected function status_parse($status)
  {
    $map = ManiphestTaskStatus::getTaskStatusMap();
    if (!isset($map[$status]))
      error("status: '$status' is not valid");
    return $status;
  }

  protected function priority_parse($priority)
  {
    $map = ManiphestTaskPriority::getTaskPriorityMap();
    if (!isset($map[$priority])) {
      warning("priority: '$priority' is not valid");
      return null;
    }
    $priority = $map[$priority];
    return $priority;
  }

  protected function blurb_fixup_references($blurb)
  {
    if (!$blurb)
      return null;

    $patterns = array();
    $replacements = array();
    foreach ($this->tasks as $id=>$task) {
      $patterns[] = "/\\b$id\\b/im";
      $replacements[] = $task->getMonogram();
    }

    $blurb = preg_replace($patterns, $replacements, $blurb);
    return $blurb;
  }

  protected function user_lookup($address)
  {
    $user = id(new PhabricatorUser())->loadOneWithEmailAddress($address);
    if (!$user)
        $user = id(new PhabricatorPeopleQuery())
          ->setViewer(PhabricatorUser::getOmnipotentUser())
	  ->withUsernames(array($address))
	  ->executeOne();
    if (!$user)
        throw new Exception("lookup of user <$address> failed");
    return $user;
  }

  protected function project_generate_PHID($id)
  {
    $PHIDType = PhabricatorProjectProjectPHIDType::TYPECONST;
    $PHID = "PHID-{$PHIDType}-ext-{$id}";
    return $PHID;
  }

  protected function project_lookup_PHIDs($ids)
  {
    $PHIDs = array();
    foreach($ids as $id) {
      $project = $this->project_lookup_by_id($id);
      $PHIDs[] = $project->getPHID();
    }
    return array_fuse($PHIDs);
  }

  protected function project_lookup_by_id($id)
  {
    $id = strtolower($id);

    $project = idx($this->projects, $id);
    if ($project)
      return $project;

    $project = id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withSlugs(array($id))
      ->executeOne();

    if ($project)
      $this->projects[$id] = $project;
    return $project;

  }

  protected function transaction_project_members_create($emails, $date)
  {
    $members = $this->users_lookup_PHIDs($emails);

    $transaction = $this->transaction_create('PhabricatorProjectTransaction', PhabricatorTransactions::TYPE_EDGE, array('+' => array_fuse($members)), $date, null)
      ->setMetadataValue('edge:type', PhabricatorProjectProjectHasMemberEdgeType::EDGECONST);

    return $transaction;
  }

  protected function project_import($json)
  {
    debug("project: begin");
    $user = $this->user_lookup($json->creator);
    $date = strtotime($json->creationDate);
    $editor = id(new PhabricatorProjectTransactionEditor())
      ->setDisableEmail(true);

    $project = $this->project_lookup_by_id($json->id);
    if ($project) {
      debug("project: {$project->getPHID()}: already exists '$json->id'");
      $transactions[] = $this->transaction_project_members_create($json->members, $date);
      $count = count($json->members);
      debug("project: {$project->getPHID()}: ensuring $count members to existing project");
      $this->transactions_apply($editor, $project, $user, $transactions);
      return;
    }

    debug("project: require creation capability for user @{$user->getUserName()} <{$json->creator}>");
    PhabricatorPolicyFilter::requireCapability(
      $user,
      PhabricatorApplication::getByClass('PhabricatorProjectApplication'),
      ProjectCreateProjectsCapability::CAPABILITY);

    $PHID = $this->project_generate_PHID($json->id);
    $slug = strtolower($json->id);
    $project = PhabricatorProject::initializeNewProject($user)
      ->openTransaction()
      ->setPHID($PHID)
      ->setName(null)
      ->setDateCreated($date);

    notice("project: {$project->getPHID()}: created '$json->id'");

    $transactions[] = $this->transaction_create('PhabricatorProjectTransaction', PhabricatorProjectTransaction::TYPE_NAME, $json->name, $date, null);

    $transactions[] = $this->transaction_create('PhabricatorProjectTransaction', PhabricatorProjectTransaction::TYPE_SLUGS, array($slug, $json->id), $date, null);

    $description = property_exists($json, 'description') ? $json->description : '';
    $description = "$description\n\nImported from the $json->tracker instance at $json->url";

    $transactions[] = $this->transaction_create('PhabricatorProjectTransaction', PhabricatorTransactions::TYPE_CUSTOMFIELD, $description, $date, null)
      ->setMetadataValue('customfield:key', 'std:project:internal:description')
      ->setOldValue(null);

    $transactions[] = $this->transaction_project_members_create($json->members, $date);

    $this->transactions_apply($editor, $project, $user, $transactions);

    $project->saveTransaction();

    notice("project: {$project->getPHID()}: imported '$json->name'");
  }

  protected function task_generate_PHID($id)
  {
    $PHIDType = ManiphestTaskPHIDType::TYPECONST;
    $PHID = "PHID-{$PHIDType}-ext-{$id}";
    return $PHID;
  }

  protected function task_generate_PHIDs($ids)
  {
    $PHIDs = array();
    foreach($ids as $id)
      $PHIDs[] = $this->task_generate_PHID($id);
    return array_fuse($PHIDs);
  }

  protected function task_lookup(PhabricatorUser $user, $phid)
  {
    $task = id(new ManiphestTask())->loadOneWhere('phid = %s', $phid);
    return $task;
  }

  protected function task_import($json)
  {
    debug("task: begin");
    $user = $this->user_lookup($json->creator);

    $PHID =$this->task_generate_PHID($json->id);
    $task = $this->task_lookup($user, $PHID);
    if ($task) {
      $this->tasks[$json->id] = $task;
      debug("task: {$PHID}: already imported '$json->id'");
      return false;
    }

    notice("task: $PHID: creating '$json->title' {$json->creationDate}");

    $description = $this->blurb_fixup_references($json->description);
    $description = "$description\n\nImported from $json->url";
    $date = strtotime($json->creationDate);

    $task = ManiphestTask::initializeNewTask($user)
      ->openTransaction()
      ->setTitle(null)
      ->setPHID($PHID)
      ->setDescription($description)
      ->setDateCreated($date);

    $editor = id(new ManiphestTransactionEditor())
      ->setDisableEmail(true);

    $title = $this->blurb_fixup_references($json->title);
    $transactions[] = $this->transaction_create('ManiphestTransaction', ManiphestTransaction::TYPE_TITLE, $title, $date, null);
    $transactions[] = $this->transaction_create('ManiphestTransaction', PhabricatorTransactions::TYPE_SUBSCRIBERS, array('=' => array($user->getPHID())), $date, null);

    $assignee = $user;
    if (property_exists($json, 'assignee'))
      $assignee = $this->user_lookup($json->assignee);
    $transactions[] = $this->transaction_create('ManiphestTransaction', ManiphestTransaction::TYPE_OWNER, $assignee->getPHID(), $date, null);

    notice("transaction: {$task->getPHID()}: initial title, subscribers and assignee");
    $this->transactions_apply($editor, $task, $user, $transactions);

    $this->tasks[$json->id] = $task;

    $task->saveTransaction();
    notice("task: {$task->getPHID()}: imported '$json->title' as {$task->getMonogram()}");
    return true;
  }

  protected function transactions_import($transactions)
  {
    $editor = id(new ManiphestTransactionEditor())
      ->setDisableEmail(true);

    $count = count($transactions);
    foreach ($transactions as $idx=>$txn) {
      $idx += 1; # show indexes starting from 1
      notice("transaction: {$txn->task->getPHID()}#{$txn->idx}: import $idx of $count: " . date('c', $txn->date));
      $this->transactions_apply($editor, $txn->task, $txn->user, array($txn->transaction));
    }
  }

  protected function task_parse_transactions($task, $json)
  {
    $count = count($json->transactions);
    $txns = array();
    foreach ($json->transactions as $idx=>$j) {
      $idx += 1; # show indexes starting from 1
      notice("transaction: {$task->getPHID()}#$idx: parsing $idx of $count: {$j->date}");
      $user = $this->user_lookup($j->actor);
      $txn = $this->transaction_parse($task, $j);

      $txns[] = new PhillTransaction($txn->getDateCreated(), $txn, $user, $task, $idx);
    }

    return $txns;
  }

  protected function transaction_create($class, $type, $value, $date, $comment)
  {
    $transaction = id(new $class())
      ->setTransactionType($type)
      ->setNewValue($value)
      ->setDateCreated($date);
    if ($comment)
      $transaction->attachComment(
        id(new ManiphestTransactionComment())->setContent($comment)
      );
    return $transaction;
  }

  protected function transaction_parse_type($type)
  {
    switch($type) {
      case "projects":    return PhabricatorTransactions::TYPE_EDGE;
      case "title":       return ManiphestTransaction::TYPE_TITLE;
      case "description": return ManiphestTransaction::TYPE_DESCRIPTION;
      case "priority":    return ManiphestTransaction::TYPE_PRIORITY;
      case "owner":       return ManiphestTransaction::TYPE_OWNER;
      case "attachment":  return PhabricatorTransactions::TYPE_COMMENT;
      case "comment":     return PhabricatorTransactions::TYPE_COMMENT;
      case "status":      return ManiphestTransaction::TYPE_STATUS;
      case "subscribers": return PhabricatorTransactions::TYPE_SUBSCRIBERS;
      case "depends":     return PhabricatorTransactions::TYPE_EDGE;
    }
    error("transaction: unknown type '$type'.");
  }

  protected function users_lookup_PHIDs($users)
  {
    $PHIDs = array();
    foreach($users as $user)
      $PHIDs[] = $this->user_lookup($user)->getPHID();
    return $PHIDs;
  }

  protected function transaction_parse(ManiphestTask $task, $json)
  {
    $date = strtotime($json->date);
    $type = $this->transaction_parse_type($json->type);
    $value = property_exists($json, 'value') ? $json->value : '';
    $comment = property_exists($json, 'comment') ? $json->comment : '';
    $comment = $this->blurb_fixup_references($comment);
    $metadata = null;

    switch($json->type) {
      case "owner":
        $value = $this->user_lookup($value)->getPHID();
        break;
      case "description":
        $desc = explode("\n", trim($task->getDescription()));
        $tagline = end($desc);
        $value = $this->blurb_fixup_references($value);
        $value = "$value\n\n$tagline";
        break;
      case "priority":
        $value = $this->priority_parse($value);
        break;
      case "attachment":
        $monogram = $this->file_ensure($task, $value)->getMonogram();
        $comment = "Uploaded {{$monogram}}\n\n$comment";
        break;
      case "status":
        $value = $this->status_parse($value);
        break;
      case "projects":
        if (property_exists($value, '+'))
          $t['+'] = $this->project_lookup_PHIDs($value->{'+'});
        if (property_exists($value, '-'))
          $t['-'] = $this->project_lookup_PHIDs($value->{'-'});
        if (property_exists($value, '='))
          $t['='] = $this->project_lookup_PHIDs($value->{'='});
        $value = $t;
        $metadata = array('edge:type', PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);
        break;
      case "subscribers":
        if (property_exists($value, '+'))
          $t['+'] = $this->users_lookup_PHIDs($value->{'+'});
        if (property_exists($value, '-'))
          $t['-'] = $this->users_lookup_PHIDs($value->{'-'});
        if (property_exists($value, '='))
          $t['='] = $this->users_lookup_PHIDs($value->{'='});
        $value = $t;
        break;
      case "depends":
        if (property_exists($value, '+'))
          $t['+'] = $this->task_generate_PHIDs($value->{'+'});
        if (property_exists($value, '-'))
          $t['-'] = $this->task_generate_PHIDs($value->{'-'});
        if (property_exists($value, '='))
          $t['='] = $this->task_generate_PHIDs($value->{'='});
        $value = $t;
        $metadata = array('edge:type', ManiphestTaskDependsOnTaskEdgeType::EDGECONST);
        break;
    }

    $transaction = $this->transaction_create('ManiphestTransaction', $type, $value, $date, $comment);
    if ($metadata)
      $transaction->setMetadataValue($metadata[0], $metadata[1]);

    notice("transaction: {$task->getPHID()}: parsed '$json->type'");
    return $transaction;
  }

  protected function transactions_apply(PhabricatorApplicationTransactionEditor $editor, PhabricatorLiskDAO $subject, PhabricatorUser $user, array $transactions)
  {
    $count = count($transactions);
    notice("transaction: {$subject->getPHID()}: applying $count transactions as @{$user->getUsername()}");
    $editor->setActor($user)
      ->setContentSource(PhabricatorContentSource::newConsoleSource())
      ->setContinueOnMissingFields(true)
      ->setContinueOnNoEffect(true)
      ->applyTransactions($subject, $transactions);
    notice("transaction: {$subject->getPHID()}: applied $count transactions as @{$user->getUsername()}");
  }

  protected function file_ensure(ManiphestTask $task, $json)
  {
    $user = $this->user_lookup($json->author);

    # interpret paths as relative to the directory of the JSON file and make sure they stay inside it
    $path = realpath($this->jsonDir . "/" .$json->data);
    if (substr($path, 0, strlen($this->jsonDir)) !== $this->jsonDir)
      error("attachment: path '{$json->data}' falls outside of '{$this->jsonDir}'.");

    $contents = file_get_contents($path);
    $file = PhabricatorFile::newFromFileData(
      $contents,
      array(
        'authorPHID' => $user->getPHID(),
        'name' => $json->name,
        'isExplicitUpload' => true,
        'mime-type' => $json->mimetype
      ));

    return $file;
  }

  protected function process_commit_level_prepare()
  {
    $connections = [];
    if (in_array ($this->commitLevel, ["global", "rollback"])) {
      $connections[] = id(new PhabricatorProject())->establishConnection('w');
      $connections[] = id(new ManiphestTask())->establishConnection('w');

      debug("process: prepare commit level '$this->commitLevel'");
      foreach($connections as $conn)
        $conn->openTransaction();
    }
    return $connections;
  }

  protected function process_commit_level_end($connections)
  {
    if ($this->commitLevel == "global") {
      debug("process: commit");
      foreach($connections as $conn)
        $conn->saveTransaction();
    }
    elseif ($this->commitLevel == "rollback") {
      debug("process: rollback");
      foreach($connections as $conn)
        $conn->killTransaction();
    }
  }

  public function process()
  {
    debug("process: begin");
    $connections = $this->process_commit_level_prepare();

    # make sure we process things in-order to make references work
    $projects = $this->json->projects;
    usort($projects, function($p1, $p2) { return strcmp($p1->creationDate, $p2->creationDate); });

    $tasks = $this->json->tasks;
    usort($tasks, function($t1, $t2) { return strcmp($t1->creationDate, $t2->creationDate); });

    debug("process: import projects");
    foreach($projects as $project)
      $this->project_import($project);

    debug("process: import tasks");
    $imported = array();
    foreach($tasks as $task)
      if ($this->task_import($task))
        $imported[$task->id] = $task;

    debug("process: parse transactions");
    # import task transactions as a separate step to be able to update
    # issue references in descriptions and comments, and apply them in global
    # time order to avoid inconsistencies
    $transactions = array();
    foreach($imported as $id=>$task) {
      $txns = $this->task_parse_transactions($this->tasks[$id], $task);
      $transactions = array_merge($transactions, $txns);
    }

    debug("process: import transactions");
    usort($transactions, function($t1, $t2) { return $t1->date - $t2->date; });
    $this->transactions_import($transactions);

    $this->process_commit_level_end($connections);
    debug("process: end");
  }
}


$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';

$args = new PhutilArgumentParser($argv);

$args->setTagline(pht('Import projects and tasks'));
$args->setSynopsis(<<<EOHELP
**phill** __JSONFILE__ [__options__]
  Fill Phabricator/Maniphest with tasks that everybody can just ignore.
EOHELP
);
$args->parseStandardArguments();
$args->parse(
  array(
    array(
      'name'    => 'input',
      'short'   => 'i',
      'param'   => 'FILE',
      'help'    => pht("The JSON-encoded file with the data to import")
    ),
    array(
      'name'    => 'transaction-level',
      'short'   => 't',
      'param'   => 'LEVEL',
      'default' => 'global',
      'help'    => pht("How much transactional the import should be: global, item or rollback (to test the import)")
    ),
    array(
      'name'    => 'verbose',
      'short'   => 'v',
      'param'   => 'LEVEL',
      'default' => '0',
      'help'    => pht("Enable verbose output, use LEVEL 2 to enable debug output")
    )
  )
);

switch($args->getArg('verbose')) {
  case 0:
    break;
  case 1:
    $logLevelThreshold = LogLevel::NOTICE;
    break;
  default:
    $logLevelThreshold = LogLevel::DEBUG;
    break;
}

$jsonfile = $args->getArg('input');
if (!$jsonfile)
  error("No file to import specified on the command line.");

$importer = new PhillImporter();
if (!$importer->load_json($jsonfile))
  error("Unable to load JSON data from '$jsonfile'.");

$importer->set_transaction_level($args->getArg('transaction-level'));
$importer->process();
