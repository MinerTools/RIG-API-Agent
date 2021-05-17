<?php

$api_ver="1.4.5";

//NICE BANNER

include_once "get_system_info.php";
require_once 'conf.php';
require_once 'json_parser.class.php';

$api_store=[];
if(!file_exists('api_store.store')){
    $api_store['maint_mode']=0;
}else{
    $api_store=json_decode(file_get_contents('api_store.store'),true);
}

if(!file_exists('../uid.txt')){
    file_put_contents('../uid.txt',hash('sha1',gethostname()));
}else{
    $i_uid=file_get_contents('../uid.txt');
    if($i_uid!==hash('sha1',gethostname())){
        file_put_contents('../uid.txt',hash('sha1',gethostname()));
    }
}

if (!function_exists('array_key_first')) {
    function array_key_first(array $arr) {
        foreach($arr as $key => $unused) {
            return $key;
        }
        return NULL;
    }
}

function build_post_fields( $data,$existingKeys='',&$returnArray=[]){
    if(($data instanceof CURLFile) or !(is_array($data) or is_object($data))){
        $returnArray[$existingKeys]=$data;
        return $returnArray;
    }
    else{
        foreach ($data as $key => $item) {
            build_post_fields($item,$existingKeys?$existingKeys."[$key]":$key,$returnArray);
        }
        return $returnArray;
    }
}

$m_client="phoenix";

$srv_list=json_decode(json_encode($server_list),true);
$R_NAME=array_key_first($srv_list);
$R_Host=$srv_list[$R_NAME]['hostname'];
$R_Port=$srv_list[$R_NAME]['port'];

$gminer_api='http://'.$R_Host.':'.$R_Port.'/stat';

$parser = new json_parser();
$parser->userinfo = gethostname();
$parser->server_list = $server_list;

// SYS INFOS
$info = new SystemInfoWindows();
$cpu = $info->getCpuUsage();
$memory = $info->getMemoryUsage();

exec('wmic os get Caption, BuildNumber, OSArchitecture /format:csv | find /v "a"',$out); // win build ver
$win_ver=explode(',',$out[1]);
$win_ver[2]=str_replace('Microsoft ','',$win_ver[2]);
$win_ver[2]=str_replace('Windows','Win',$win_ver[2]);
$win_ver[2]=str_replace('Professionnel','Pro',$win_ver[2]);
exec('cscript //nologo "%systemroot%\system32\slmgr.vbs" /dli',$out); // win licence
$out=implode(' ',$out);
if(strpos($out,'avec licence')!==false){ $win_lic=true; }else{ $win_lic=false; }

$parser->sys_info=(object)[];
$parser->sys_info->memory=$memory;
$parser->sys_info->cpu=$cpu;
$parser->sys_info->win_inf=$win_ver;
$parser->sys_info->win_licence=$win_lic;

$parser->parse_all_json_rpc_calls();

//GMINER
function check_gminer(){
    global $m_client;
    global $gminer_api;
    global $res;

    $ch = curl_init($gminer_api);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res=curl_exec($ch);
    if (!curl_errno($ch)) {
        switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
            case 200:  # OK
                $m_client="gminer";
        }
    }
    curl_close($ch);
}

if(!isset($parser->miner_data_results->$R_NAME->version)){
    check_gminer();
}else{
    if($parser->miner_data_results->$R_NAME->version==""){
        check_gminer();
    }
}

if($m_client=="gminer"){
    function secondsToTime($seconds) {
        $dtF = new \DateTime('@0');
        $dtT = new \DateTime("@$seconds");
        return $dtF->diff($dtT)->format('%ad %hh %im');
    }
    $gminer_api=json_decode($res,true);

    $parser->miner_status->$R_NAME=3;
    if($gminer_api['uptime']!==0){
        $parser->miner_status->$R_NAME=1;
    }

    $parser->miner_data_results->$R_NAME->version=$gminer_api['miner'];
    $parser->miner_data_results->$R_NAME->coin=$gminer_api['algorithm'];
    $parser->miner_data_results->$R_NAME->uptime=secondsToTime($gminer_api['uptime']);
    $parser->miner_data_results->$R_NAME->pool=$gminer_api['server'];

    $glob_hash=$glob_accepted=$glob_rejected=$glob_stales=$glob_invalid=0;
    $parser->miner_data_results->$R_NAME->card_stats=(object)[];
    foreach($gminer_api['devices'] as $id => $inf){
        $parser->miner_data_results->$R_NAME->card_stats->$id=(object)[];

        $glob_hash+=$inf['speed'];
        $glob_accepted+=$inf['accepted_shares'];
        $glob_rejected+=$inf['rejected_shares'];
        $glob_stales+=$inf['stale_shares'];
        $glob_invalid+=$inf['invalid_shares'];

        $parser->miner_data_results->$R_NAME->card_stats->$id->hashrate=round($inf['speed']/1000000,2);;
        $parser->miner_data_results->$R_NAME->card_stats->$id->temp=$inf['temperature'];
        $parser->miner_data_results->$R_NAME->card_stats->$id->fan=$inf['fan'];

        $parser->miner_data_results->$R_NAME->card_stats->$id->name=$inf['name'];

        $parser->miner_data_results->$R_NAME->card_stats->$id->id=$inf['gpu_id'];
        $parser->miner_data_results->$R_NAME->card_stats->$id->bus=$inf['bus_id'];
        $parser->miner_data_results->$R_NAME->card_stats->$id->accepted=$inf['accepted_shares'];
        $parser->miner_data_results->$R_NAME->card_stats->$id->stales=$inf['stale_shares'];
        $parser->miner_data_results->$R_NAME->card_stats->$id->r_shares=$inf['rejected_shares'];
        // $parser->miner_data_results->$R_NAME->card_stats->$id->s_shares=$inf['stale_shares'];
        $parser->miner_data_results->$R_NAME->card_stats->$id->i_shares=$inf['invalid_shares'];
    }

    $glob_hashrate=round($glob_hash/1000000,2);

    $parser->miner_data_results->$R_NAME->stats->global_hashrate=$glob_hashrate;
    $parser->miner_data_results->$R_NAME->stats->hashrate=$glob_hashrate;

    $parser->miner_data_results->$R_NAME->stats->shares=$glob_accepted;
    $parser->miner_data_results->$R_NAME->stats->stale=$glob_stales;
    $parser->miner_data_results->$R_NAME->stats->rejected=$glob_invalid;

    $parser->miner_data_results->$R_NAME->stats->d_rejected=$glob_rejected;
    $parser->miner_data_results->$R_NAME->stats->d_invalid=$glob_invalid;
    
    $parser->global_hashrate=$glob_hashrate;
    $parser->shares_per_minute=$gminer_api['shares_per_minute'];
}

$uid=hash('sha1',gethostname());
$parser->UID=$uid;

$parser->api_version=$api_ver;


// MAINTENANCE MODE //
if(isset($api_store['maint_mode'])){
    $parser->maintenance_mode=$api_store['maint_mode'];
}else{
    $parser->maintenance_mode=0;
}

if(isset($_GET['maint'])){
    if($_GET['maint']=='1'){
        $parser->maintenance_mode=1;
        $api_store['maint_mode']=1;
    }else{
        $parser->maintenance_mode=0;
        $api_store['maint_mode']=0;
    }
}

////////////////////////////// MINERSTAT INFOS  //////////////////////////////

$mstat_api=array();
if($config['MinerStat_id']!==""){
    $mstat_api=json_decode(file_get_contents('https://api.minerstat.com/v2/stats/'.$config['MinerStat_id']),true);
}

// include wallet id
$parser->wallet_id=$config['Wallet_id'];

// include minerstat
$parser->minerstat_infos=$mstat_api;

 ///////////////////////// SEND INFOS TO MTOOLS ////////////////////////
	header('Content-Type: application/json');
	
	// $parser->parse_all_json_rpc_calls();
	$result=json_encode($parser);
	
    file_put_contents('..\data.json',$result);

    $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://mtools.gaerisson-softs.fr/stats.php?get_rig_json='.$uid);
        curl_setopt($ch, CURLOPT_POST, 1);
        // curl_setopt($ch, CURLOPT_SAFE_UPLOAD, false); // requis à partir de PHP 5.6.0
        curl_setopt($ch, CURLOPT_POSTFIELDS, build_post_fields($parser));
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_exec($ch);
	curl_close($ch);
 /////////////////////////////////////////////////////////////////////////

 ////////////////////////////// GET COMMANDS /////////////////////////////
	if($config['Listen_cust_command']==1){
		$instruct=json_decode(file_get_contents('http://mtools.gaerisson-softs.fr/stats.php?get_instruct='.$uid),true);
		if(isset($instruct['command'])){
			$command=$instruct['command'];
			exec('cmd.exe /c "'.$command.'>output_cmd.txt"');
		}
	}

 ////////////////////////////// AUTORESTART //////////////////////////////
	// REJECTED STATUS
	if($config['AutoRestart_bs']==1){
        if(isset($parser->miner_data_results->$R_NAME->stats->rejected)){
            $rejected=$parser->miner_data_results->$R_NAME->stats->rejected;
		
            $autorestart_exec=0;
            if($rejected>=$config['AutoRestart_nb_bs']){
                $autorestart_exec=1;
            }
            
            if($autorestart_exec==1){
                $command="restart_mining.bat";
                exec('cmd.exe /c "'.$command.'>output_ar_cmd.txt"');
                $autorestart_exec=0;
            }
        }
	}
	
////////////////////////////// LED CONTROL //////////////////////////////
$LED_ENABLE = $config['LED_Status_Enable'];

if($LED_ENABLE==1){
    function httpPost($url, $data){
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=UTF-8'));
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS,$data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        curl_close($curl);
        return $response;
    }
    
    $med_temp = $config['LED_Status']['LED_med_temp'];
    $max_temp = $config['LED_Status']['LED_max_temp'];
    
    // CHECK RIG STATUS
    if(!isset($parser->miner_data_results->$R_NAME->stats)){
        $status=1;
    }else{
        $status=0;
    
        // REJECTED STATUS
        $shares=$parser->miner_data_results->$R_NAME->stats->shares;
        $rejected=$parser->miner_data_results->$R_NAME->stats->rejected;
        
        if(($rejected*100)/$shares>10){
            $status=4;
        }
    
        $overheat=0;
        if(isset($parser->miner_data_results->$R_NAME->card_stats)){
            foreach ($parser->miner_data_results->$R_NAME->card_stats as $key => $stat) {
                if($stat->temp>=$max_temp){
                    $overheat=2;
                }elseif($stat->temp>=$med_temp){
                    if($overheat!==2){
                        $overheat=1;
                    }
                }
            }
        }
    
        if($overheat==1){
            $status=2;
        }elseif($overheat==2){
            $status=3;
        }
    }
    
    // $status=0;
    // 0 = good
    // 1 = not minning
    
    // 2 = medium temp
    // 3 = overheat
    
    // 4 = > bad share
    
    $state=$config['LED_Status']['LED_state']; // ON / OFF
    $brightness=$config['LED_Status']['LED_brightness']; // 0 -> 255
    $rgb=array(255,0,0); // R G B
    $flash=0; // -1 = infinite /nb flash
    $transition=5; // 0 -> 10
    $effect="null"; // null / flash / colorfade_fast / colorfade_slow
    
	if($config['LED_Status']['LED_AutoFade_Night']){
		if(date('H')=='00'){
			$brightness=20;
		}
		
		if(date('H')=='09'){
			$brightness=$config['LED_Status']['LED_brightness'];
		}
	}
    
    if($status==0){ // Good
        $rgb=array(0,100,255); // R G B
    }elseif($status==1){
        $rgb=array(255,0,0);
    }elseif($status==2){
        $rgb=array(255,255,0);
        $flash=-1;
        $transition=0;
    }elseif($status==3){
        $rgb=array(255,0,0);
        $flash=-1;
        $transition=0;
    }elseif($status==4){
        $rgb=array(255,222,0);
    }else{
        $rgb=array(228,0,255);
    }
    
	$flash_str="";
    if($flash!==0){ $flash_str="\"flash\":".$flash.','; }
    $data='{"state":"'.$state.'","color":{"r":'.$rgb[0].',"g":'.$rgb[1].',"b":'.$rgb[2].'},"transition": '.$transition.','.$flash_str.'"brightness":'.$brightness.',"effect":"'.$effect.'"}';
    
    httpPost($config['LED_Status']['LED_API_URL'],$data);
}

////////////////////////////// CAM STREAM //////////////////////////////
$CAM_Enable = $config['CAM_Stream_Enable'];

if($CAM_Enable==1){
    $arrContextOptions=array(
        "ssl"=>array(
            "verify_peer"=>false,
            "verify_peer_name"=>false,
        ),
    );  
    
    $response = file_get_contents($config['CAM_Stream_URL'], false, stream_context_create($arrContextOptions));
    if(!($response)){
        $data_b64=$response;
    }else{
        $no_img="/9j/4AAQSkZJRgABAQEBLAEsAAD//gATQ3JlYXRlZCB3aXRoIEdJTVD/4gKwSUNDX1BST0ZJTEUAAQEAAAKgbGNtcwQwAABtbnRyUkdCIFhZWiAH5QADAAgAAAAgAChhY3NwTVNGVAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA9tYAAQAAAADTLWxjbXMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA1kZXNjAAABIAAAAEBjcHJ0AAABYAAAADZ3dHB0AAABmAAAABRjaGFkAAABrAAAACxyWFlaAAAB2AAAABRiWFlaAAAB7AAAABRnWFlaAAACAAAAABRyVFJDAAACFAAAACBnVFJDAAACFAAAACBiVFJDAAACFAAAACBjaHJtAAACNAAAACRkbW5kAAACWAAAACRkbWRkAAACfAAAACRtbHVjAAAAAAAAAAEAAAAMZW5VUwAAACQAAAAcAEcASQBNAFAAIABiAHUAaQBsAHQALQBpAG4AIABzAFIARwBCbWx1YwAAAAAAAAABAAAADGVuVVMAAAAaAAAAHABQAHUAYgBsAGkAYwAgAEQAbwBtAGEAaQBuAABYWVogAAAAAAAA9tYAAQAAAADTLXNmMzIAAAAAAAEMQgAABd7///MlAAAHkwAA/ZD///uh///9ogAAA9wAAMBuWFlaIAAAAAAAAG+gAAA49QAAA5BYWVogAAAAAAAAJJ8AAA+EAAC2xFhZWiAAAAAAAABilwAAt4cAABjZcGFyYQAAAAAAAwAAAAJmZgAA8qcAAA1ZAAAT0AAACltjaHJtAAAAAAADAAAAAKPXAABUfAAATM0AAJmaAAAmZwAAD1xtbHVjAAAAAAAAAAEAAAAMZW5VUwAAAAgAAAAcAEcASQBNAFBtbHVjAAAAAAAAAAEAAAAMZW5VUwAAAAgAAAAcAHMAUgBHAEL/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wgARCAHCAyADAREAAhEBAxEB/8QAHAABAQABBQEAAAAAAAAAAAAAAAECBAUGBwgD/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEAMQAAAB4eAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAApAAAAAAAAAAAAAAAAAAUgIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADEAAAAAAAAAAAFIAAAAAAAAAAAAAAAAAAAAAAAAAACkAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQgBSAAAAAAAAAAAAAAApAAAAAAAAAAAAAAAAAAACkAAABSAAApAAAAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAYgAAAAAAAAAAAAAAAAAAAAFAIAAAAAAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAAGRrDSGAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAAfU9UnRxwQxAAAAAAAAAAAAAAAAAAAAAAAAAAMQAAAAAAAAAAAAAAAAAAAADM3c9PnQpw0yMAU058igyMSAAAAA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zMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzM8GZmZ4MzMzMzMzwZmZmZmZmZmeDMzMzMzwZmZmZmZmZmZmZngzMzMzMzMzMzMzMzMzM+QHkeDwZ4PgGfEHg8GeDyPgHzAfEHg8GeDMzPB4PB4PgGeDM+QGZmZmfID+ZgAAAAAAAD/wAkAAAB/wCCAAD/AH+gAAAAAAAAAAAAAAAAAAAAAAAgABAAAAAAAB//AN//AP8A9/8A/wD/AP8A+/8A/Q2+Kl7jaomz2FjukB//2gAMAwEAAgADAAAAEJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJBJJJJJJJJJJJJJJJJJIJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJAAIIIAJIABAAAJAAIAIJAJIBBBIIABAJJBBIJAIIAJBBBBIBBIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAJJJJJJJJJJJJBJJJJJJJJJJJJJJJJJJJJJJJJJJIJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJAAAAAAAAAAAAAAIBAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABJBJJJJJJJJJJJJJJIJJJJJJJJJJJJJJJJJJJIJJJJBJJIJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJIAIABAAIBAAAIIBBABIJAAJAJABJIJAAIBBBABIAJBIAAAAAAJAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAJJJJJJJJJJJJJJJJJJJJJIAJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJAAAAAAAAAAAAAAAAAAAAAAJBABAAAAAAAAAAAAAAAAAAAAAAAAJJJJJJJJJJJJJJJJJJJJJIBABIJJIJJJJJJJJJJJJJJJJJJJJJAAAAAAAAAAAAAAAAAAAAAAIJIABBAAAAAAAAAAAAAAAAAAAAAAJJJJJJIJIJJJJJJBIJJJJJIIIJIAIJIBJJBJJJJJBJBJBAJJIJJJJJJJJJJJJJJJJJJJJJJJJAAIAJJJJJJJJJJJJJJJJJJJJJJJAAAAAAAAAAAAAAAAAAAAAAAIBJIBIAAAAAAAAAAAAAAAAAAAAAJJJJJJJJJJJJJJJJJJJJJJJJBBIABJJJJJJJJJJJJJJJJJJJJJAAAAAAAAAAAAAAAAAAAAAAABIJJAJAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABIAAIAJAAAAAAAAAAAAAAAAAAAAAJJJJJJJJJJJJJJJJJJJJJJIJIBIJIJJJJJJJJJJJJJJJJJJJJJAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAAAAJJJJJJJJJJJJJJJJIABJIBAABBBBIBJJJBJJJJJJJJJJJJJJJJAAAAAAAJAAAAABAABBAIJJIJBBBIBJBABJAAABAABAAAAIABAAAAAAAAAAAAAAAAAAAAIIAAJBJBJIJJIJIIAAAAAAAAAAAAAAAAJIJABJBIJJJIBBIIJIBAIBJBBJIIAJBJIBBJJJIJJBIBBBJJAIJJJJJIJJJJIJJBJJJJJJBJJJJJJIBIJJIJJJBJJJJJJJJIJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAABIAAAAIABAAAABABABABAAABAAAAAAAAAIBAAAAAAAAAAAJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJIJJJJJJJJJJJBBAABBAAAABIAAAIAAAIAAAIAAAAAIBBABAAAIAABAAIAAAAAAJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJAIJAABIIBBIBJJAAAJAAAJAJIAAIIAAJAAIAAJBIAAIIAABBBIJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJBJJJJJJJJJBJJBJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJIJJAJJJJJBJBJBJIBIBJJIJIJJJJJBJJJJJIJJJJBIJIJJJJAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAAAIAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAJABJJJJJJJBAJIAJJBIBJIJJJIABJJJABIJJJJIJBJJIIJJJIJJJP/8QAFBEBAAAAAAAAAAAAAAAAAAAAwP/aAAgBAwEBPxAyJ//EABQRAQAAAAAAAAAAAAAAAAAAAMD/2gAIAQIBAT8QMif/xAAtEAADAQACAgIBAQgCAwEAAAAAARFxECExQVFhIIEwQFBgkaHB8LHhcNHxgP/aAAgBAQABPxD+ZAAAAAAAAAAAAAAAAAqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKiopUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRSoq/8k1lFZRWVlZRRWUVlFFZWVlFZWVlZRRWUVlZWVlZWVlZWUUVlZXwrKyisrKysrKyisrKyvhWUUVlFFZRWV8KKysrKysrKyisorKyisoorKysrKKKKKysrKKysrK/5oAAAAAAAAAAAAAAAAAQVcKuFXJBUVFX4hV+YFRV+IVfgKuFRVyVFRVyVEFXCoq4VfiFRHCoqKirhVwqKuFRURwq4VfgKirhVwq4VcKirkq4VFXClKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKUpSlKX+fb/F7+618WlLeLeLfys5s4s4t/Gvi3i38LS38LebCv8K/zs4t4r/Cz9haWlK+G2yz8bPxsKy3iss5vNLeKKKysr4UUVlFFFZWVlZRRWVlZRWUVlZWVwrKyiisrKyisrKysrKKyisorKyivhWVlZWVlZWVlZWVlFFZWUVlZRRXCsrKKysrKyiiiiiuFcKKyisrKysrKK//ACaAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABf5HAAAAAAAAAAAC/X8UAAAAAAAAEfsQCP34AAAAEfuIAAR+4gABV+AqI/AVEcI4VclXCPxCrhVyVcKvwFRUQVFX4hUVclRVwjhUVFRVwqKvxCojhVwq4VckFRV+AqKuFRV+zACOSoqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqIKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKil4qKioqKioqKioqKioqKiCoqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqIKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKhqjtafSlPJqgV9iYh2r9Z4H2fyVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVF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";
        $data_b64=(base64_decode($no_img));    
    }
	
	$data=array('img_b64'=>$data_b64);
	
	$ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://mtools.gaerisson-softs.fr/stats.php?cam_data='.$uid);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, build_post_fields($data));
    curl_exec($ch);
	curl_close($ch);
}

file_put_contents('api_store.store',json_encode($api_store));

?>