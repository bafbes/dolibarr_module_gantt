<?php

require 'config.php';

set_time_limit(0);
ini_set('memory_limit','1024M');

dol_include_once('/projet/class/project.class.php');
dol_include_once('/projet/class/task.class.php');
dol_include_once('/commande/class/commande.class.php');
dol_include_once('/workstation/class/workstation.class.php');
dol_include_once('/of/class/ordre_fabrication_asset.class.php');
dol_include_once('/comm/action/class/actioncomm.class.php');

dol_include_once('/gantt/lib/gantt.lib.php');

// Project -> Order -> OF -> Task
//<script src="../../codebase/locale/locale_fr.js" charset="utf-8"></script>
$row_height = 20;

$langs->load('workstation@workstation');
$langs->load('gantt@gantt');

$skin = empty($conf->global->GANTT_SKIN) ? 'dhtmlxgantt_terrace.css' : $conf->global->GANTT_SKIN;

llxHeader('', $langs->trans('GanttProd') , '', '', 0, 0, array(
		'/gantt/lib/dhx/codebase/dhtmlxgantt.js',
		'/gantt/lib/dhx/codebase/ext/dhtmlxgantt_smart_rendering.js',
		/*'/gantt/lib/dhx/codebase/ext/dhtmlxgantt_quick_info.js', // display info popin on click event*/
		'/gantt/lib/dhx/codebase/ext/dhtmlxgantt_tooltip.js',
		'/gantt/lib/dhx/codebase/locale/locale_fr.js',
		'/gantt/lib/he.js'
		),
    array('/gantt/lib/dhx/codebase/sources/skins/'.$skin,'/gantt/css/gantt.css') );

dol_include_once('/core/lib/project.lib.php');
dol_include_once('/gantt/class/color_tools.class.php');

$langs->load("users");
$langs->load("projects");
$langs->load("workstation@workstation");
$langs->load("gantt@gantt");
$fk_project = (int)GETPOST('fk_project');
$scale_unit = GETPOST('scale');

if($fk_project>0) {

	$object = new Project($db);
	$object->fetch($fk_project);
	// Security check
	$socid=0;
	if ($user->societe_id > 0) $socid=$user->societe_id;
	$result = restrictedArea($user, 'projet', $id,'projet&project');

	$head=project_prepare_head($object);
	dol_fiche_head($head, 'anotherGantt', $langs->trans("Project"),0,($object->public?'projectpub':'project'));
	$linkback = '<a href="'.DOL_URL_ROOT.'/projet/list.php">'.$langs->trans("BackToList").'</a>';

	$morehtmlref='<div class="refidno">';
	// Title
	$morehtmlref.=$object->title;
	// Thirdparty
	if ($object->thirdparty->id > 0)
	{
		$morehtmlref.='<br>'.$langs->trans('ThirdParty') . ' : ' . $object->thirdparty->getNomUrl(1, 'project');
	}
	$morehtmlref.='</div>';

	// Define a complementary filter for search of next/prev ref.
	if (! $user->rights->projet->all->lire)
	{
		$objectsListId = $object->getProjectsAuthorizedForUser($user,0,0);
		$object->next_prev_filter=" rowid in (".(count($objectsListId)?join(',',array_keys($objectsListId)):'0').")";
	}

	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

}
else {

	dol_fiche_head();

}

	$day_range = empty($conf->global->GANTT_DAY_RANGE_FROM_NOW) ? 90 : $conf->global->GANTT_DAY_RANGE_FROM_NOW;

	$range = new stdClass();
	$range->date_start = 0;
	$range->date_end= 0;
	$range->sql_date_start = date('Y-m-d',strtotime('-'.$day_range.' days'));
	$range->sql_date_end = date('Y-m-d',strtotime('+'.$day_range.' days'));

	if($fk_project>0) {
		$range->date_start = $project->date_start;
	        $range->date_end= $project->date_end;
        	$range->sql_date_start = date('Y-m-d',$project->date_start);
	        $range->sql_date_end = date('Y-m-d',$project->date_end);
	}

	$range->autotime = true;

	if(GETPOST('range_start')!='') {
		$range->date_start = dol_mktime(0, 0, 0, GETPOST('range_startmonth'), GETPOST('range_startday'), GETPOST('range_startyear'));
		$range->sql_date_start = date('Y-m-d',$range->date_start);
		$range->autotime = false;
	}

	if(GETPOST('range_end')!='') {
		$range->date_end= dol_mktime(0, 0, 0, GETPOST('range_endmonth'), GETPOST('range_endday'), GETPOST('range_endyear'));
		$range->sql_date_end = date('Y-m-d',$range->date_end);
		$range->autotime = false;
	}

	$TData = $TWS = $TLink = $TTask = array();

	$TElement = _get_task_for_of($fk_project);
//pre($TElement,1);exit;

	_get_workstation(); // init tableau de WS

	$open = false;
	if(GETPOST('open')) $open = true;
	else if(GETPOST('close')) $open = false;
	else if(GETPOST('open_status')) $open = true;

	$move_projects_mode = GETPOST('moveProjects')!='' ? true : false;

	$close_init_status = !empty($fk_project) || $open ? 'true': 'false';

	$t_start  = $t_end = 0;

			foreach($TElement as &$projectData ) {

			    if(!empty($projectData['object'])) {

			    	$project = &$projectData['object'];

    				$fk_parent_project = null;

    				if(empty($conf->global->GANTT_DO_NOT_SHOW_PROJECTS)) {
    				    $TData[$project->ganttid] = _get_json_data($project, $close_init_status,null,0,0,'',$move_projects_mode);
    					$fk_parent_project= $project->ganttid;
    				}

    				if($move_projects_mode) continue; // view just project

    				_get_events( $TData,$TLink,$project->id);

    				$time_task_limit_no_after = $time_task_limit_no_before_init = $time_task_limit_no_before = 0;

    				if(!empty($projectData['childs'])) {
        				foreach($projectData['childs'] as &$orderData) {

        					$order= &$orderData['object'];

        					$fk_parent_order = null;

        					if($order->element =='milestone') {
        						if($order->bound == 'before') {
        							$time_task_limit_no_before=$time_task_limit_no_before_init = strtotime(date('Y-m-d 00:00:00',$order->date));
        						}
        						else {
        							$time_task_limit_no_after =$time_task_limit_no_after_init= strtotime(date('Y-m-d 23:59:59',$order->date));
        						}
        					}

        					if(empty($conf->global->GANTT_HIDE_INEXISTANT_PARENT) || $order->id>0 || $order->element!='commande') {
        						$TData[$order->ganttid] = _get_json_data($order, $close_init_status, $fk_parent_project, $time_task_limit_no_before,$time_task_limit_no_after);
        						$fk_parent_order = $order->ganttid;
        					}
        					else {
        						$fk_parent_order = $fk_parent_project;
        					}

        					$time_task_limit_no_after=$time_task_limit_no_after_init;

        					if(!empty($orderData['childs'])) {

            					foreach($orderData['childs'] as &$ofData) {

            						$of = &$ofData['object'];


            						$fk_parent_of = null;

            						if($of->element =='milestone' && $of->date<$time_task_limit_no_after) {
            							$time_task_limit_no_after= strtotime(date('Y-m-d 23:59:59',$of->date));
            						}

            						if((!empty($conf->of->enabled) && (empty($conf->global->GANTT_HIDE_INEXISTANT_PARENT) || $of->id>0) ) || $of->element!='of') {
            							$TData[$of->ganttid] = _get_json_data($of, $close_init_status, $fk_parent_order, $time_task_limit_no_before,$time_task_limit_no_after);
            							$fk_parent_of= $of->ganttid;
            						}
            						else{
            							$fk_parent_of = $fk_parent_order;
            						}

            						// Add order child tasks
            						$taskColor='';

            						$time_task_limit_no_before=$time_task_limit_no_before_init;
            						if(!empty($ofData['childs'])) {
                						foreach($ofData['childs'] as &$wsData) {

                							$ws = $wsData['object'];

                							if(empty($ws->element)) $ws->element = 'workstation';
                							//var_dump($ws->element);

                							if($ws->element == 'workstation') $ws->ganttid = 's'.$fk_parent_of.$ws->ganttid;

                							if($ws->element =='milestone' && $ws->date>$time_task_limit_no_before) {
                								$time_task_limit_no_before = strtotime(date('Y-m-d 00:00:00',$ws->date));
                							}

                							if((!empty($ws->id) && empty($conf->global->GANTT_HIDE_WORKSTATION)) || ($ws->element!='workstation')) {
                								$TData[$ws->ganttid] = _get_json_data($ws, $close_init_status, $fk_parent_of, $time_task_limit_no_before,$time_task_limit_no_after);
                								$fk_parent_ws = $ws->ganttid;
                							}
											else{
												$fk_parent_ws = $fk_parent_of;
											}

                							$taskColor='';
                							if($ws->element=='workstation' && !empty($ws->background) && !empty($conf->global->GANTT_HIDE_WORKSTATION)) {
                								$taskColor = $ws->background;
                							}

                							if(!empty($wsData['childs'])) {
	                							foreach($wsData['childs'] as &$task) {

	                								$task->ws = &$ws;

	                								$TTask[] = $task->id;

	                								if($task->date_start<time())$TTaskOlder[] = $task->id;

	                								$TData[$task->ganttid] = _get_json_data($task, $close_init_status, $fk_parent_ws, $time_task_limit_no_before,$time_task_limit_no_after,$taskColor);

													if($task->fk_task_parent>0) {
														$linkId = count($TLink)+1;
														//$TLink[$linkId] =' {id:'.$linkId.', source:"T'.$task->fk_task_parent.'", target:"'.$task->ganttid.'", type:"0"}';

														$TLink[$linkId] = array('id'=>$linkId, 'source'=>'T'.$task->fk_task_parent, 'target'=>$task->ganttid, 'type'=>'0');
													}

	                							}
                							}
                						}
            						}
            					}
        					}
        				}
    				}
			    }
			}

			if(!$move_projects_mode) _get_events($TData,$TLink);

			checkDataGantt($TData, $TLink);

			if($range->autotime){

				if(empty($range->date_start) && empty($range->date_end)) {
					$range->date_end=$range->date_start=time();
				}

				if(empty($range->date_start)) {
					$range->date_start = $range->date_end = time()-86400;
				}
				if(empty($range->date_end)) {
					$range->date_end = $range->date_start;
				}
				$range->date_end+=864000;

				if($range->date_end > $range->date_start + 86400 * 366) $range->date_end = $range->date_start + 86400 * 366;
			}

			$formCore=new TFormCore('auto','formDate','get');
			?>
			<table border="0" width="100%"><tr><td >
			<?php

			echo $formCore->hidden('open_status',(int)$open);
			echo $formCore->hidden('fk_project',$fk_project);
			echo $formCore->hidden('scrollLeft', 0);

			$form = new Form($db);
			echo $form->select_date($range->date_start, 'range_start');
			echo $form->select_date($range->date_end,'range_end');

			if($fk_project == 0 && !$move_projects_mode){
				if(!$open) echo $formCore->btsubmit($langs->trans('OpenAllTask'), 'open');
				else  echo $formCore->btsubmit($langs->trans('ClosedTask'), 'close');


			}

			echo '</td><td>';
			
			if(!empty($conf->workstation->enabled) && !$move_projects_mode) {
			   $PDOdb=new TPDOdb;
			   echo $formCore->combo('', 'restrictWS', TWorkstation::getWorstations($PDOdb, false, true) + array(0=>$langs->trans('NotOrdonnanced')) , (GETPOST('restrictWS') == '' ? -1 : GETPOST('restrictWS'))).'<br />';

			}

			echo $formCore->combo('', 'scale', array('day'=>$langs->trans('Days'),'week'=>$langs->trans('Weeks')) , GETPOST('scale'));
			echo '</td><td>';
			if(!empty($conf->of->enabled)) echo $formCore->texte($langs->trans('OFFilter'), 'ref_of',GETPOST('ref_of'),5,255).'<br />';
			echo $formCore->texte($langs->trans('CMDFilter'), 'ref_cmd',GETPOST('ref_cmd'),5,255);

			echo '</td><td>';
			
			echo $formCore->hidden('open_status',(int)$open);
			echo $formCore->hidden('fk_project',$fk_project);
			echo $formCore->hidden('scrollLeft', 0);

			echo $formCore->btsubmit($langs->trans('ok'), 'bt_select_date');


			if($fk_project == 0 && !$move_projects_mode){
			    echo '</td><td align="center">';
			    echo $formCore->btsubmit($langs->trans('MoveProjects'), 'moveProjects');
			    echo '</td><td>';
			}

			if($range->autotime && $range->date_end - $range->date_start > (86400 * 60)) {

				echo '</td></tr></table><div class="error">La plage détectée faisant plus de 60 jours, merci de confirmer ces dates</div>';
				llxFooter();

				exit;
			}

			if(!$move_projects_mode) {

    			echo '</td><td align="right" width="20%"><a href="javascript:downloadThisGanttAsCSV()" >'.img_picto($langs->trans('DownloadAsCSV'), 'csv@gantt').'<a>';

    			?>
    			</td><td align="right" valign="top">
    			<a id="move-all-task" style="display:inline" href="javascript:;" onclick="$(this).hide();moveTasks('<?php echo implode(',', $TTask) ?>');" class="button"><?php echo $langs->trans('MoveAllTasks') ?></a>
    			<?php
    			if (!empty($TTaskOlder)) {
    			     ?><br /><a id="move-all-task" style="display:inline" href="javascript:;" onclick="$(this).hide();moveTasks('<?php echo implode(',', $TTaskOlder) ?>');" class="button"><?php echo $langs->trans('MoveAllOlderTasks') ?></a><?php
    			}
    			
    			?>
    			<span id="ajax-waiter" class="waiter"><br /><?php echo $langs->trans('AjaxRequestRunning') ?></span>
    			</td><?php

			}

			?>
			</tr></table>
<?php 
$formCore->end();
?>
			<div id="gantt_here" style='width:100%; height:100%;'></div>

			<script type="text/javascript">
			$body = $("body");
			$body.addClass("loading");

			<?php

				echo 'var workstations = '.json_encode($workstationList).';';
			?>

			gantt.config.types.of = "of";
			gantt.locale.labels.type_of = "<?php echo $langs->trans('OF'); ?>";

			gantt.config.types.order = "order";
			gantt.locale.labels.type_order = "<?php echo $langs->trans('Order'); ?>";

			gantt.config.types.milestone = "milestone";
			gantt.locale.labels.type_milestone = "<?php echo $langs->trans('Release'); ?>";

			gantt.config.types.actioncomm = "actioncomm";
			gantt.locale.labels.type_milestone = "<?php echo $langs->trans('Agenda'); ?>";

			gantt.config.types.delay = "delay";
			gantt.locale.labels.type_delay = "<?php echo $langs->trans('Delay'); ?>";

			var tasks = {
				data:[

			<?php

			echo implode(",\n",$TData);

			?>

	    ],
	    links:[
	       <?php

	       $Tmp=array();
	       foreach($TLink as $k=>&$link) {
	       		echo ' {id:'.$link['id'].', source:"'.$link['source'].'", target:"'.$link['target'].'", type:"'.$link['type'].'"},'."\n";
	       }

	       ?>
	    ]
	};

	gantt.templates.grid_row_class = function(start, end, obj){
		var r = '';
		if((obj.objElement == 'of' || obj.objElement == 'project' || obj.objElement == 'order')&& obj.date_max && obj.date_max>0) {

			var d = new Date(obj.date_max * 1000);
			if(+d < +obj.end_date - 1000) {
				r="gantt_late";
			}
			else if(+d - 86400000 < +obj.end_date && obj.objElement == 'of') {
				r="gantt_maybelate";
			}

		}
		else if(obj.objElement == 'project_task') {

                        if(obj.time_task_limit_no_before && obj.time_task_limit_no_before> (+obj.start_date / 1000)){
                                r+=" task_too_early";
                        }
                        else if(obj.time_task_limit_no_after && obj.time_task_limit_no_after>0 && (+obj.end_date/1000)>obj.time_task_limit_no_after + 86399 ) {
				r+=" task_too_late";
                        }

                }
		return r;
	};
	gantt.templates.task_class = function(start, end, obj){

		var r = '';

		if(obj.type == gantt.config.types.of){
			r = "gantt_of";
		}
		if(obj.type == gantt.config.types.order){
			r = "gantt_order";
		}
		else if(obj.type == gantt.config.types.release){
			r = "gantt_release";
		}
		else if(obj.type == gantt.config.types.delay){
			r = "gantt_delay";
		}
		else if(obj.owner) {
			r = "workstation_"+obj.workstation;
		}

		if(obj.date_max && obj.date_max>0) {

			var d = new Date(obj.date_max * 1000);
			if(+d < +obj.end_date) {

				r+=" gantt_late";
			}

		}

		return r;
	}

	gantt.templates.scale_cell_class = function(date){
	    if(date.getDay()==0||date.getDay()==6){
	        return "weekend";
	    }
	};
	gantt.templates.task_cell_class = function(item,date){
	    if(date.getDay()==0||date.getDay()==6){
	        return "weekend" ;
	    }
	    else if(date.getTime() < <?php echo strtotime('-1day')*1000; ?>) {
	    	return "lateDay" ;
	    }
	};

	gantt.templates.task_text = function(start, end, task){

		return task.text;
	}
	gantt.templates.rightside_text = function(start, end, task){

		var r = "";

		if(task.objElement == 'project_task') {

			if(task.workstation == 0) {
				r+="<?php echo  addslashes(img_info($langs->trans('NoWorkstationOnThisTask'))); ?>";
			}

			if(task.time_task_limit_no_before && task.time_task_limit_no_before> (+task.start_date / 1000)){
				r+="<?php echo  addslashes(img_warning().$langs->trans('TooEarly')); ?>";
			}
			else if(task.time_task_limit_no_after && task.time_task_limit_no_after>0 && (+task.end_date/1000)>task.time_task_limit_no_after + 86399 ){
				r+="<?php echo  addslashes(img_warning().$langs->trans('TooLate')); ?>";
			}

		}
		else {
			null;

		}

		return r;
	}

	gantt.config.columns = [
	    {name:"text",       label:"<?php echo $langs->transnoentities('Label') ?>",  width:"*", tree:true
		    , template:function(obj) {

				var r = '';

				if(obj.id[0] == 'T' || obj.objElement == 'milestone' || obj.objElement == 'project_task_delay') {
					r = obj.text;
				}
				else {
					r = '<strong>'+obj.text+'</strong>';
				}

				return r;

	    	}
	    },
	    {name:"start_time",   label:"<?php echo $langs->transnoentities('DateStart') ?>",  template:function(obj){
			return gantt.templates.date_grid(obj.start_date);
	    }, align: "center", width:70 },
	    /*{name:"progress",   label:"<?php echo $langs->transnoentities('Progression') ?>",  template:function(obj){
			return obj.progress ? Math.round(obj.progress*100)+"%" : "";
	    }, align: "center", width:60 },*/
	    {name:"duration",   label:"<?php echo $langs->transnoentities('Duration').' ('.$langs->transnoentities('shortDays').')' ?>", align:"center", width:60},

	   <?php
	   		if(!empty($conf->global->GANTT_ALLOW_PREVI_TASK)) echo '{name:"add",        label:"",  width:44 },';

	   	?>

	   	{name:"automove",   label:' ',  template:function(obj){
		   	if(obj.id[0] == 'T') {
				return '<a class="misted" href="javascript:taskAutoMove(gantt.getTask(\''+obj.id+'\'));"><?php echo img_picto($langs->transnoentities('AutoMove'), 'move.png@gantt'); ?></a>'
				+ '&nbsp; <a class="misted" href="javascript:splitTask(gantt.getTask(\''+obj.id+'\'));"><?php echo img_picto($langs->transnoentities('Split'), 'split.png@gantt'); ?></a>';
		   	}

		   	return '';
    	}, align: "center", width:54 },

	];

	// Define local lang
    gantt.locale.labels["section_progress"] = "<?php echo $langs->transnoentities('Progress') ?>";
    gantt.locale.labels["section_workstation"] = "<?php echo $langs->transnoentities('Workstation') ?>";
    gantt.locale.labels["section_needed_ressource"] = "<?php echo $langs->transnoentities('needed_ressource') ?>";
    gantt.locale.labels["section_planned_workload"] = "<?php echo $langs->transnoentities('planned_workload') ?>";

	gantt.config.lightbox.sections = [
        {name: "description", height: 26, map_to: "text", type: "textarea", focus: true},
        {name: "workstation", label:"<?php echo $langs->transnoentities('Workstation'); ?>", height: 22, type: "select", width:"60%", map_to: "workstation",options: [
            <?php echo _get_workstation_list(); ?>
        ]},

        {name: "needed_ressource", height: 26, map_to: "needed_ressource", type: "textarea"},
        {name: "progress", height: 22, map_to: "progress", type: "select", options: [
            {key:"0", label: "<?php echo $langs->transnoentities('NotStarted') ?>"},
            {key:"0.1", label: "10%"},
            {key:"0.2", label: "20%"},
            {key:"0.3", label: "30%"},
            {key:"0.4", label: "40%"},
            {key:"0.5", label: "50%"},
            {key:"0.6", label: "60%"},
            {key:"0.7", label: "70%"},
            {key:"0.8", label: "80%"},
            {key:"0.9", label: "90%"},
            {key:"1", label: "<?php echo $langs->transnoentities('Complete') ?>", width:"60%"}
        ]},

        {name: "time", type: "time", map_to: "auto", time_format:["%d", "%m", "%Y"]},
        {name: "planned_workload", height: "22", map_to: "planned_workload", type:"select", options:[
			<?php
				dol_include_once('/core/lib/date.lib.php');

				for($i=0;$i<1000000;$i+=900) {
					echo '{key:"'.$i.'", label:"'.convertSecondToTime($i,'allhourmin').'"},';
				}

			?>
        ]}
    ];


	gantt.config.grid_width = 390;
	gantt.config.date_grid = "%d/%m/%y";

	gantt.config.scale_height  = 40;
	gantt.config.row_height = <?php echo $row_height; ?>;

	gantt.config.start_date = new Date();
	gantt.config.end_date = new Date();

	<?php

	if($scale_unit=='week') {
		echo 'gantt.config.scale_unit = "week";
        gantt.config.date_scale = "%d/%m (%W)";
        gantt.config.fit_tasks = false;gantt.config.step = 1;
        gantt.config.round_dnd_dates = false;
        gantt.config.subscales = [
				{ unit:"year", step:1, date:"'.$langs->transnoentities('Year').' %Y"}
		];';
	}
	else {
		echo 'gantt.config.fit_tasks = true; gantt.config.subscales = [
				{ unit:"week", step:1, date:"'.$langs->transnoentities('Week').' %W'.( date('Y',$range->date_end)!=date('Y',$range->date_start) ? ' %Y' : '').'"}
			];
		';
	}

	?>

	// add text progress information
	/*gantt.templates.progress_text = function(start, end, task){
		return "<span style='text-align:left;'>"+Math.round(task.progress*100)+ "% </span>";
	};*/

	gantt.templates.tooltip_text = function(start,end,task){

		var r ='';
		if(task.text) {
		    r = "<strong>"+task.text+"</strong><br/><?php echo $langs->trans('Duration') ?> "
		   			+ task.duration + " <?php echo $langs->trans('days') ?>";
		   	if(task.planned_workload) r+=" / "+(Math.round(task.planned_workload / 3600 * 10) / 10)+" <?php echo $langs->trans('hours') ?>";
			if(task.needed_ressource) r+="<br/><?php echo $langs->trans('NeededRessource') ?> : "+task.needed_ressource;

			if(task.start_date) r+= "<br /><?php echo $langs->trans('FromDate') ?> "+task.start_date.toLocaleDateString()
			if(task.end_date && task.duration>1) r+= " <?php echo $langs->trans('ToDate') ?> "+task.end_date.toLocaleDateString();
		}

		if(task.time_task_limit_no_after) {
			d=new Date(task.time_task_limit_no_after*1000);
			r+="<br /><?php echo $langs->trans('HighBound') ?> "+d.toLocaleDateString();

		}
		if(task.time_task_limit_before_after) {
			d=new Date(task.time_task_limit_no_before*1000);
			r+="<br /><?php echo $langs->trans('LowBound') ?> "+d.toLocaleDateString();

		}

		if(task.workstation == 0) {
			r+="<?php echo  addslashes('<div class="error">'.img_info().$langs->trans('NoWorkstationOnThisTask').'</div>'); ?>";
		}
		else if(workstations[task.workstation]){
			//console.log(workstations[task.workstation]);
			r+="<?php echo  addslashes('<div class="info">'.img_info()) ?> "+workstations[task.workstation].name+ "</div>";
		}

		if(task.date_max && task.date_max>0) {

			var d = new Date(task.date_max * 1000);

			r+="<br /><?php echo $langs->trans('TodoFor') ?> "+d.toLocaleDateString();

		}

		return r;

	};

	gantt.attachEvent("onBeforeLinkAdd", function(id,link){

		return false; // on empêche d'ajouter du lien

	});

	gantt.attachEvent("onLinkDblClick", function(id){
		return false;
	});

	gantt.attachEvent("onTaskOpened", function(id){

	});
	gantt.attachEvent("onTaskClosed", function(id){

	});
	gantt.attachEvent("onGanttScroll", function (left, top) {

		$('#formDate input[name=scrollLeft]').val(left);
		$('div.ws_container ').scrollLeft( $('div.gantt_hor_scroll').scrollLeft() );
	});

	/*gantt.attachEvent("onBeforeLightbox", function(id) {
	    var task = gantt.getTask(id);

		gantt.getLightboxSection('workstation').setValue(task.workstation);
	    return true;
	});*/

	gantt.attachEvent("onAfterTaskAdd", function(id,task){
		//console.log('createTask',id, task);
		var start = task.start_date.getTime();
		var end = task.end_date.getTime();
		$.ajax({
			url:"<?php echo dol_buildpath('/gantt/script/interface.php',1); ?>"
			,data:{
				ganttid:id
				,start:start
				,end:end
				,label:task.text
				,duration:task.duration
				,progress:0
				,parent:task.parent
				,put:"task"
			}
			,method:"post"
		}).done( function(newid) {
			gantt.changeTaskId(id, newid);

			var task = gantt.getTask(newid);
			task.objId = newid.substring(1);
			task.objElement = 'project_task';


			// TODO set workstation and update capacity
		});
	});



	gantt.attachEvent("onTaskDblClick", function(id,e){
		if(id[0] === 'T') {
			var task = gantt.getTask(id);

			task.text = task.title;

			if(task.planned_workload>1) {
				var m = task.planned_workload%900;

				if(m>0) task.planned_workload = task.planned_workload - m + 900;
			}
			else {
				task.planned_workload = 0;
			}
			
			console.log(task);
			return true;
		}
		else if(id[0] === 'P') {
			window.open('<?php echo dol_buildpath('/projet/card.php', 1) ?>?id='+id.substr(1));
			return false;
		}
		else if(id[0] === 'O') {
			window.open('<?php echo dol_buildpath('/commande/card.php', 1) ?>?id='+id.substr(1));
			return false;
		}
		else if(id[0] === 'M') {
			window.open('<?php echo dol_buildpath('/of/fiche_of.php', 1) ?>?id='+id.substr(1));
			return false;
		}
		else {
			return false;
		}
	});


	gantt.attachEvent("onTaskCreated", function(task){
		task.workstation = 0;

		console.log('onTaskCreated',task);
	    return true;
	});



	gantt.attachEvent("onTaskClick", function(id,e){
		if(id[0] == 'T') {
			//ask_delete_task(id);
		}
		return true;
	});

	gantt.attachEvent("onBeforeTaskDelete", function(id,item){
		var task = gantt.getTask(id);
		return delete_task(task.id,1,0);
	});

	var start_task_drag = 0;
	var end_task_drag =  0;
	var rightLimit = null;
	var leftLimit = null;
	var alertLimit = false;
	var leftLimitON = false;
	var rightLimitON = false;
	var TAnotherTaskToSave = {};

	gantt.attachEvent("onBeforeTaskDrag", function(sid, parent, tindex){
		var task = gantt.getTask(sid);
<?php

if(!$move_projects_mode) {
  ?>
	if(task.id[0]!='T' && task.id[0]!='A') {
		gantt.message('<?php echo $langs->trans('OnlyTaskCanBeMoved') ?>');

		return false;
	}

	initTaskDrag(task);
  <?php
}

?>


		return true;
	});

	var drag_start_date = 0;

	gantt.attachEvent("onAfterTaskDrag", function(id, mode, e){
		var modes = gantt.config.drag_mode;
		<?php
				if($move_projects_mode) {
				    ?>

				    <?php
				}
				else {
	    ?>

	   if(mode == modes.progress) {
			task = gantt.getTask(id);
			task.progress = Math.round(task.progress * 10) / 10;

		}
	   else if(mode == modes.move || mode == modes.resize){
		    /* on regul de décalage du au snap grid */
			task = gantt.getTask(id);
			var diff = +task.start_date - drag_start_date;

			moveChild(task, diff );
	    }

		for(idTask in TAnotherTaskToSave) {

			task = gantt.getTask(idTask);
			regularizeHour(task);
			gantt.refreshTask(task.id);

			saveTask(task);

		}

		TAnotherTaskToSave = {};

		<?php
				}
		?>
	});

//gantt.callEvent("onTaskDrag",[s.id,e.mode,o,r,t]);

	gantt.attachEvent("onTaskDrag", function(id, mode, task, original){
		drag_start_date = +task.start_date;
	    var modes = gantt.config.drag_mode;

        <?php
				if($move_projects_mode) {
				    echo 'if(mode != modes.move) return false; ';
				}
	    ?>

		if(mode == modes.move || mode == modes.resize){

	        var diff = original.duration*(1000*60*60*24);

	        dragTaskLimit(task, diff, mode);
	        moveChild(task, task.start_date - original.start_date );

	        if(!moveParentIfNeccessary(task)) {
				return false;
	        }

	    }
	    return true;
	});

	gantt.attachEvent("onBeforeTaskChanged", function(id, mode, old_event){

		var task = gantt.getTask(id);

		return saveTask(task, old_event);

	});

    gantt.attachEvent("onLightboxSave", function(id, task, is_new){
        var old_event=gantt.getTask(id);


		//to get the value
		task.workstation = gantt.getLightboxSection('workstation').getValue();
		gantt.getLightboxSection('workstation').setValue(task.workstation);
        //task.workstation = gantt.getLightboxSection('workstation ').getValue();
		task.title = task.text;

        return saveTask(task, old_event,is_new);
    })
	gantt.attachEvent("onBeforeTaskDisplay", function(id, task){

		if (typeof task.visible != "undefined" && task.visible == 0){
	    	/*console.log(id,task.visible);*/
	        return false;
	    }



	    
	    return true;
	});

// Add more button to lightbox
	gantt.config.buttons_left=["dhx_save_btn","dhx_cancel_btn","edit_task_button","automove","split"];

	gantt.locale.labels["edit_task_button"] = "<?php echo $langs->trans('ModifyTask'); ?>";
	gantt.locale.labels["automove"] = "<?php echo $langs->trans('AutoMove'); ?>";
	gantt.locale.labels["split"] = "<?php echo $langs->trans('Split'); ?>";

	gantt.attachEvent("onLightboxButton", function(button_id, node, e){
	    if(button_id == "edit_task_button"){
	        var id = gantt.getState().lightbox;
	        gantt.updateTask(id);
	        gantt.hideLightbox();
	        pop_edit_task(id.substring(1));
	    }
	    else if(button_id == "automove"){
	        var id = gantt.getState().lightbox;
	        task = gantt.getTask(id);

	        gantt.hideLightbox();

			taskAutoMove(task);

	    }
	    else if(button_id == "split"){
	        var id = gantt.getState().lightbox;
	        console.log(id);
	        task = gantt.getTask(id);

	        gantt.hideLightbox();

			splitTask(task);

	    }
	});

	$(document).ready(function() {
		<?php
		if(GETPOST('scrollLeft')>10 && $scale_unit!='week') {

				    echo ' gantt.scrollTo('.(int)GETPOST('scrollLeft').',0); ';
		}
		else {
			echo 'updateWSRangeCapacity(0);';
		}

		?>
    });

	gantt.config.drag_links = false;
	gantt.config.autoscroll = false;
	gantt.config.autosize = "y";

<?php
if($move_projects_mode) {
    echo 'gantt.config.drag_resize = false;gantt.config.drag_progress = false;';
}

?>

	gantt.init("gantt_here", new Date("<?php echo date('Y-m-d', $range->date_start) ?>"), new Date("<?php echo date('Y-m-d', $range->date_end) ?>"));
	//modSampleHeight();
	gantt.parse(tasks);

	<?php
	if($fk_project == 0 || !empty($conf->global->GANTT_SHOW_WORKSTATION_ON_1PROJECT)) {

	    echo 'var w_cell = $(\'div.gantt_task_bg div.gantt_task_cell\').first().outerWidth();';

	    ?>
		var w_workstation = $('div.gantt_bars_area').width();
		var w_workstation_title = $('div.gantt_grid_data').width();

		$('style[rel=drawLine]').remove();

        var html_style = '<style rel="drawLine" type="text/css">'
                           +' div.gantt_bars_area div.workstation div.gantt_task_cell { width:'+w_cell+'px; text-align:center; } '
                           +' div.gantt_bars_area div.workstation { height:12px; font-size:10px; } '
                         +'</style>';

        $(html_style).appendTo("head");

		<?php

		$cells = '';
		$t_cur = $range->date_start;
		if($scale_unit=='week') {
		    $day_of_week = date('N',$t_cur);
		    $t_cur=strtotime('-'.$day_of_week.'days + 1 day', $t_cur);
		}

		while($t_cur<=$range->date_end) {
			$cells.='<div class="gantt_task_cell" date="'.date('Y-m-d', $t_cur).'">N/A</div>';

			if($scale_unit=='week') {
			    $t_cur = strtotime('+1week',$t_cur);
			}
			else {
			    $t_cur = strtotime('+1day',$t_cur);
			}

		}

		echo 'function replicateDates() {
				$(\'div.ws_container_label div.dates, div.ws_container>div div.dates\').remove();
				$(\'div.ws_container_label\').append(\'<div class="gantt_row dates" style="height:12px;">&nbsp;</div>\');
				$(\'div.ws_container>div\').append($(\'#gantt_here div.gantt_container div.gantt_task div.gantt_task_scale div.gantt_scale_line:eq(1)\').clone().addClass(\'dates\'));


		}';

		echo 'function updateAllCapacity() {

		if($("div.ws_container_label").length == 0) {
			$("body").append(\'<div class="ws_container_label"></div>\');
			$("body").append(\'<div class="ws_container"><div></div></div>\');
		}


		';

		foreach($TWS as &$ws) {
			if($ws->type!='STT' && !is_null($ws->id)) {
				?>
				if($("div#workstations_<?php echo $ws->id; ?>.gantt_row").length == 0 ) {

					$('div.ws_container_label').append('<div class="gantt_row workstation_<?php echo $ws->id; ?>" style="text-align:right; width:'+w_workstation_title+'px;height:13px;padding-right:5px;font-size:10px;"><a href="#" onclick="$(\'#formDate select[name=restrictWS]\').val(<?php echo $ws->id ?>);$(\'#formDate\').submit();"><?php echo addslashes($ws->name) . ' ('.$ws->nb_hour_capacity.'h - '.$ws->nb_ressource.')'; ?></a></div>');
					$('div.ws_container>div').append('<div class="workstation gantt_task_row gantt_row" id="workstations_<?php echo $ws->id ?>" style="width:'+w_workstation+'px; "><?php echo $cells; ?></div>');

				}
				<?php
			}

		}

		if($flag_task_not_ordonnanced) {

			?>

			if($("div#workstations_0.gantt_row").length == 0 ) {

				$('div.ws_container_label').append('<div class="gantt_row workstation_0" style="text-align:right; width:'+w_workstation_title+'px;height:13px;padding-right:5px;font-size:10px;"><a href="#" onclick="$(\'#formDate select[name=restrictWS]\').val(0);$(\'#formDate\').submit();"><?php echo $langs->trans('NotOrdonnanced') ?></a></div>');
				$('div.ws_container>div').append('<div class="workstation gantt_task_row gantt_row" id="workstations_0" style="width:'+w_workstation+'px; "><?php echo $cells; ?></div>');

			}

			<?php

		}


		echo '
		replicateDates();
		$("div.ws_container").css({
			width : $("#gantt_here div.gantt_task").width()
			, left:$("#gantt_here div.gantt_task").offset().left
		});

		$("div.ws_container_label").css({
			left:$("#gantt_here div.gantt_grid").offset().left
			,width:$("#gantt_here div.gantt_grid").width()
			,height : $("div.ws_container").outerHeight()
		}); ';


		echo ' }

		';


	}
	else {

		?>
		function updateAllCapacity() {}

		$( document ).ready(function(){
		if($("div.ws_container_label").length == 0) {
           $("body").append('<div class="ws_container"><div>&nbsp;</div></div>');

           }

           $("div.ws_container").css({
                 width : $("#gantt_here div.gantt_task").width()
                , left:$("#gantt_here div.gantt_task").offset().left
           });
		   $("div.ws_container>div").css({
				 width : $("#gantt_here div.gantt_task div.gantt_data_area").width()
		   });
		});
		<?php
	}

	require 'lib/gantt.js.php';

	?>

	var checkScrollStop;

	$("div.ws_container").scroll(function(e) {
		var sl = $(this).scrollLeft();

		gantt.scrollTo(sl,null);

	    replicateDates();

	    clearTimeout(checkScrollStop);
	    checkScrollStop = setTimeout(function() {
	        updateWSRangeCapacity(sl);
	    }, 250);

	});

	/*
	*	Recalcul la taille des colonnes du workflow
	*/
	$( document ).ready(function(){
		$body.removeClass("loading");
		var colWidth = $( ".gantt_task_row .gantt_task_cell" ).first().width();
		/*window.alert(colWidth);*/
		/*console.log('colWidth',colWidth);*/

		$( ".ws_container .gantt_task_cell" ).width(colWidth);

		setInterval(function(){
			$.ajax({
				url:"<?php echo dol_buildpath('/gantt/script/interface.php',1) ?>?get=keep-alive"
			});
		}, 60000);
	});

	</script>


	<style type="text/css" media="screen">
		.weekend { background: #f4f7f4 !important; }
		.gantt_selected .weekend {
			background:#FFF3A1 !important;
		}

		<?php

		foreach($TWS as &$ws) {

			$color = $ws->background;
			if(strlen($color) == 7) {

				echo '.ws_container_label .workstation_'.$ws->id.'{
					background:'.$color.';
				}';

			}
		}

		?>


	</style>
	<?php

	dol_fiche_end();
	echo '<div class="modalwaiter"></div>';
	llxFooter();


