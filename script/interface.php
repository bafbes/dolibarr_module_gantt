<?php

	require '../config.php';
	dol_include_once('/projet/class/project.class.php');
	dol_include_once('/projet/class/task.class.php');
	dol_include_once('/comm/action/class/actioncomm.class.php');

	$langs->load("gantt@gantt");

	$get=GETPOST('get');
	$put = GETPOST('put');
	switch ($put) {

		case 'gantt':

			echo _put_gantt($_REQUEST);

			break;

		case 'task':

			echo _create_task($_REQUEST);

			break;

		case 'projects':

			_put_projects($_REQUEST['TProject']);

			echo 1;

			break;

		case 'delete_task':

			_delete_task();

			break;
		case 'split':
			__out(_split_task(GETPOST('taskid'), GETPOST('tache1'), GETPOST('tache2')),'json');
			break;

	}

	switch ($get) {

		case 'workstation-capacity':
			__out(_get_ws_capactiy(  GETPOST('wsid'),GETPOST('t_start'),GETPOST('t_end') ),'json' );

			break;
	}

	function _split_task($taskid, $task1time, $task2time) {
		global $db, $user, $conf;

		$task =new Task($db);
		$task->fetch($taskid);
		$task->fetch_optionals($task->id);

		$task->planned_workload = $task1time * 3600;
		$task->update($user);

		$task2 = new Task($db);
		foreach($task as $k=>$v) {

			if($k!='id' && $k!='progress' &$k!='duration_effective' && $k!='ref' ) {
				$task2->{$k} = $v;
			}

		}

		$task2->planned_workload = $task2time * 3600;

		$defaultref='';
		$obj = empty($conf->global->PROJECT_TASK_ADDON)?'mod_task_simple':$conf->global->PROJECT_TASK_ADDON;
		if (! empty($conf->global->PROJECT_TASK_ADDON) && is_readable(DOL_DOCUMENT_ROOT ."/core/modules/project/task/".$conf->global->PROJECT_TASK_ADDON.".php"))
		{
			require_once DOL_DOCUMENT_ROOT ."/core/modules/project/task/".$conf->global->PROJECT_TASK_ADDON.'.php';
			$modTask = new $obj;
			$defaultref = $modTask->getNextValue(0,$task2);
		}

		if (is_numeric($defaultref) && $defaultref <= 0) $defaultref='';

		$task2->ref = $defaultref;

		$task2->fk_task_parent = $task->id;

		$task2->create($user);

		return $task2->id;
	}

	function _create_task($data) {
		global $db, $user, $langs,$conf;

		$p=new Project($db);
		if($p->fetch(0,'PREVI')<=0) {

			$p->ref='PREVI';
			$p->title = $langs->trans('Provisionnal');

			$p->create($user);
		}


		$o=new Task($db);

		$defaultref='';
		$obj = empty($conf->global->PROJECT_TASK_ADDON)?'mod_task_simple':$conf->global->PROJECT_TASK_ADDON;
		if (! empty($conf->global->PROJECT_TASK_ADDON) && is_readable(DOL_DOCUMENT_ROOT ."/core/modules/project/task/".$conf->global->PROJECT_TASK_ADDON.".php"))
		{
			require_once DOL_DOCUMENT_ROOT ."/core/modules/project/task/".$conf->global->PROJECT_TASK_ADDON.'.php';
			$modTask = new $obj;
			$defaultref = $modTask->getNextValue($p->thirdparty,null);
		}

		if (is_numeric($defaultref) && $defaultref <= 0) $defaultref='';

		$o->ref = $defaultref;

		$o->date_c = time();

		$o->fk_project = $p->id;

		$o->label = $data['label'];

		$o->date_start = $data['start'] / 1000;
		$o->date_end = ($data['end'] / 1000) - 1; //Pour que cela soit à 23:59:59 de la vieille
		$o->progress = $data['progress'] * 100;

		$o->planned_workload = $data['duration'] * 3600 * 7; //7h par jour, à revoir

		//check parent projet to set correct task parent. 0 if parent comme from an other project and task->id if parent comme from PREVI Project task
		$parenttaskId = _get_task_id_from_task_gantt_id($data['parent']);
		$parenttask = new Task($db);
		$parenttask->fetch($parenttaskId);

		if($parenttask->fk_project === $p->id)
		{
			$o->fk_task_parent = $parenttaskId;
		}
		else
		{
			$o->fk_task_parent = 0;
		}

		$o->array_options['options_fk_gantt_parent_task'] = $data['parent'];


		$r = $o->create($user);

		if($r>0) return 'T'.$r;
		else {

			var_dump($r, $o);
		}

	}

	function _put_gantt($data) {
		global $db, $user;

		switch($data['ganttid'][0]) {
			case 'T':
				$description = preg_replace("/^(PREVI )/","",$data['description']);
				$o=new Task($db);
				$o->fetch((int)$data['id']);
				if(empty($o->array_options))$o->fetch_optionals($o->id);

				$o->label = $description;
				$o->date_start = $data['start'] / 1000;
				$o->date_end = ($data['end'] / 1000) - 1; //Pour que cela soit à 23:59:59 de la vieille
				$o->planned_workload = (int)$data['planned_workload'];
				$o->progress = $data['progress'] * 100;
				$o->array_options['options_fk_workstation'] = (int)$data['workstation'];
				$o->array_options['options_needed_ressource'] = (int)$data['needed_ressource'];
				$res = $o->update($user);

			if($res<0 ) {
				var_dump($res);
			}
				return $res;
				break;

			case 'A':
				$description = preg_replace("/^(AGENDA )/","",$data['description']);
				$o=new ActionComm($db);
				$o->fetch((int)$data['id']);
				$o->fetch_optionals($o->id);
				$o->label = $description;
				$o->datep = $data['start'] / 1000;
				$o->datef = ($data['end'] / 1000) - 1; //Pour que cela soit à 23:59:59 de la vieille
				$o->array_options['options_fk_workstation'] = (int)$data['workstation'];
				$o->array_options['options_needed_ressource'] = (int)$data['needed_ressource'];

				//var_dump($data);
				return $o->update($user);

				break;


		}

	}

	function _get_ws_capactiy($wsid, $t_start, $t_end) {
		global $conf;

		if(empty($conf->workstation->enabled)) return array();

		dol_include_once('/workstation/class/workstation.class.php');

		$PDOdb=new TPDOdb;

		$ws=new TWorkstation;
		$ws->load($PDOdb, $wsid);

		$Tab = $ws->getCapacityLeftRange($PDOdb, $t_start, $t_end);

		// TODO Faire une PR sur le module workstation pour inclure cette fonction
		_getCapacityLeftRangeAgenda($PDOdb,$ws,$Tab,$t_start, $t_end);

		return $Tab;
	}

	function _put_projects(&$TProject) {
		global $db,$langs,$conf,$user;

		foreach($TProject['tasks'] as &$data ) {

			$type = $data['id'][0];
			$id = substr($data['id'],1);


			if($type=='P') {

				$project = new Project($db);
				$project->fetch($id);

				$project->date_start = $data['start'] / 1000;
				$project->date_end = $data['end'] / 1000;

				$project->update($user);
			}
			else{

				$task=new Task($db);
				$task->fetch($id);

				$task->date_start = $data['start'] / 1000;
				$task->date_end = $data['end'] / 1000;
					//var_dump($data['depends']);
				if(!empty($data['depends'])) {

					list($d1) = explode(',', $data['depends']);

					$task->fk_task_parent = (int)substr($TProject['tasks'][$d1-1]['id'],1);
				//	var_dump($d1,$TProject['tasks'][$d1]['id'],$task->fk_task_parent );
				}

				$task->update($user);

			}



		}

	}


	function _delete_task ()
	{
		global $user,$db,$langs;

		$prevent_child_deletion = (bool)GETPOST('prevent_child_deletion');
		$task_id = _get_task_id_from_task_gantt_id(GETPOST('task_id'));

		$retDatas= array('result' => false, 'msg' => '');

		$task=new Task($db);
		if($task->fetch($task_id)>0)
		{
			//TODO check user rights

			$deleteError = false;

			if ($prevent_child_deletion &&  ( $task->hasChildren() || $task->hasTimeSpent()) )
			{
				$deleteError= true;
				$retDatas['msg'] = $langs->trans('TaskHasChildrenOrHasTimeSpent');
			}

			if(!$deleteError)
			{
				$retDatas['result'] = $task->delete($user);
			}

		}

		echo json_encode($retDatas);
		exit;
	}

	function _get_task_id_from_task_gantt_id($id)
	{
		if(substr ( $id, 0,1) === 'T')
		{
			return  (int) substr ( $id, 1);
		}
		else
		{
			return  0;
		}

	}

	// TODO Faire une PR sur le module workstation pour inclure cette fonction
	function _getCapacityLeftRangeAgenda(&$PDOdb,&$ws,&$TDate,$t_start, $t_end){

		$t_cur = $t_start;

		while($t_cur<=$t_end) {
			$date=date('Y-m-d', $t_cur);
			$capacity = $TDate[$date]; //$ws->dayCapacity($t_cur);
			if($capacity===false || $capacity==='NA') $TDate[$date] = 'NA';
			else {

				$sql = "SELECT a.id, aex.needed_ressource, a.datep AS dateo , a.datep2 AS datee
							FROM ".MAIN_DB_PREFIX."actioncomm a
								LEFT JOIN ".MAIN_DB_PREFIX."actioncomm_extrafields aex ON (aex.fk_object=a.id)
							WHERE ";
				$sql.="'".$date."' BETWEEN a.datep AND a.datep2 ";
				$sql.=' AND aex.fk_workstation = '.$ws->id.' ';

				$Tab = $PDOdb->ExecuteASArray($sql);

				foreach($Tab as &$row) {
					$capacity-= $row->needed_ressource;
				}

				$TDate[$date] = $capacity;

			}
			$t_cur=strtotime('+1day', $t_cur);
		}
		return $TDate;
	}
