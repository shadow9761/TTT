<?php


namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ServerListModel;
use App\Models\LogonRecordModel;
use App\Models\AuditCheckListModel;
use App\Models\VeEmployeeModel;
use App\Models\ApiLogsModel;
use App\Models\WhiteListUserModel;
use App\Models\AuditUsersModel;
use App\Tools\Logger;
use Cache;
use Log;
use Exception;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Redis;


class AuditLogonController extends Controller
{

	// generate html context for http://192.168.111.49/aud/audReport
	public static function auditReportForm(Request $request)
	{
		$htmlStr = '<div class="table-responsive-sm"><div class="row"><div class="col"><div class="alert">';
		$htmlStr = $htmlStr . self::genSummaryHtml();
		$htmlStr = $htmlStr . self::genDetailHtml();
		$htmlStr = $htmlStr . self::genWhiteList();
		$htmlStr = $htmlStr . self::genAuditList();
		$htmlStr = $htmlStr . "</div></div></div></div> ";
		return view('audit_logon_report', compact('htmlStr'));

	}


	// generate html context for http://192.168.111.49/aud/audReport
	protected static function genAuditList()
    {

        $columns = array( '#' , 'FAB_SITE', 'IP' , 'DB_USER' , 'DESCRIPTION'  );

        $html = '<h2>DB Audit List </h2><table class="table table-striped" id="AuditListUser" ><thead><tr>' ;

        foreach($columns as $col){

             $html = $html . '<th scope="col" class="text-primary" >'.$col.'</th> ' ;

           }

        $html = $html . '</tr></thead><tbody>';

        $results = AuditUsersModel::customQuery();
        $cnt = 1 ;

        foreach($results as $row){


        $temp = "<tr><th scope=\"row\">".$cnt++."</th><td> $row->FAB_SITE </td><td> $row->IP </td><td> $row->DB_USER </td><td> $row->DESCRIPTION </td></tr>";
        $html = $html . $temp ;
        }

        $html = $html . '</tbody></table>' ;

        return $html ;

    }


	// generate html context for http://192.168.111.49/aud/audReport
	protected static function genWhiteList()
    {

        $columns = array( '#' , 'FAB_SITE', 'IP' , 'DB_USER' , 'WHITE_LIST'  );

        $html = '<h2>USER White List </h2><table class="table table-striped" id="WhiteListUser" ><thead><tr>' ;

        foreach($columns as $col){

            $html = $html . '<th scope="col" class="text-primary" >'.$col.'</th> ' ;

        }

        $html = $html . '</tr></thead><tbody>';

        $results = WhiteListUserModel::customQuery();
        $cnt = 1 ;

        foreach($results as $row){


            $temp = "<tr><th scope=\"row\">".$cnt++."</th><td> $row->FAB_SITE </td><td> $row->IP </td><td> $row->DB_USER </td><td> $row->WHITE_LIST </td></tr>";
            $html = $html . $temp ;
        }

        $html = $html . '</tbody></table>' ;

        return $html ;

    }

	// generate html context for http://192.168.111.49/aud/audReport
	protected static function genSummaryHtml()
	{
		$startDay =  date('Y-m-d' , strtotime('-7 day'));
		#$endDay =  date('Y-m-d' , strtotime('0 day'));
		$endDay =  date('Y-m-d H:i:s' , strtotime('0 day'));
		$columns = array( '#' , 'VALID', 'FAB_SITE' , 'OSUSER' , 'CURRENT_USER' , 'IP' , 'COUNT'  , 'AUDIT_DAY', 'DOC_NO' , 'REASON' , 'COMMENT' );
		$cnt = 1 ; 
		$html = '<h2>Audit Summary  : ( '.$startDay . ' ~ ' . $endDay .' ) </h2><table class="table table-striped " id="sortSummaryTable"  ><thead><tr>' ;

		foreach($columns as $col){

                        $html = $html . '<th scope="col" class="text-primary" >'.$col.'</th> ' ;

                }
		$html = $html . '</tr></thead><tbody>';


		$logonRecord = LogonRecordModel::whereBetween('LOGON_TIME' , [$startDay , $endDay])->whereRaw(" ( VALID = 'N' or DOC_NO is not null)")->groupBy( 'VALID', 'FAB_SITE' , 'OSUSER' , 'CURRENT_USER' , 'IP' , 'DOC_NO')->selectRaw(" VALID, FAB_SITE , OSUSER , `CURRENT_USER` , IP , DOC_NO , count(*) as CNT , DATE_FORMAT(min(LOGON_TIME), '%Y-%m-%d') as AUDIT_DAY ")->orderBy('AUDIT_DAY' , 'DESC')->orderBy('DOC_NO' , 'DESC')->get() ;

		foreach($logonRecord as $row){
			$reason = null ; 
			$comment = null ; 
			$href = null ; 

			if(!is_null($row->DOC_NO) ){

				$resUser = self::checkVisEraUser( $row->OSUSER , $row->HOST , $row->TERMINAL , $row->IP ) ;

				$userName = $resUser->get('userName');
				$userId = $resUser->get('userId');

                        	#$reason =  str_replace("\r\n" , '<br>' , self::getRedmineIssueLastNote( $row->DOC_NO  ));
                        	$noteInfo =   self::getRedmineIssueLastNote( $row->DOC_NO  );
							$reason = str_replace("\r\n" , '<br>' , $noteInfo->get('NOTE') );
							if( empty($reason) ) { $reason = self::getTimeSpendComment( $row->DOC_NO , $userId  ) ;   }
                        	$comment = '';
                        	$href = env('REDMINE_API_URL') . '/issues/' . $row->DOC_NO . ' target="_blank"' ;	

				#boss version 
				/*
				$personInfo = self::findPersonInfo( $userName  );
                        	$reason =  str_replace("\r\n" , '<br>' , self::getRedmineIssueLastNote( $row->DOC_NO , $personInfo->get('REDMINE_ID') ));
							if( empty($reason) ) { $reason = self::getTimeSpendComment( $row->DOC_NO , $personInfo->get('REDMINE_ID')  ) ;   }
                        	$comment = str_replace("\r\n" , '<br>' , self::getRedmineIssueLastNote( $row->DOC_NO , $personInfo->get('BOSS_REDMINE_ID') ));
                        	$comment = '';
                        	$href = env('REDMINE_API_URL') . '/issues/' . $row->DOC_NO . ' target="_blank"' ;	
				*/

			}

			$temp = "<tr><th scope=\"row\">".$cnt++."</th><td class=\"text-danger\" > $row->VALID </td><td> $row->FAB_SITE </td><td> $row->OSUSER </td><td> $row->CURRENT_USER </td><td> $row->IP </td><td> $row->CNT </td><td> $row->AUDIT_DAY </td><td><a href=$href  > $row->DOC_NO </a></td><td> $reason </td><td> $comment </td></tr>";

			$html = $html . $temp ; 

		}	
		
			
		$html = $html . '</tbody></table>' ;

		return $html ; 	

	}

	// generate html context for http://192.168.111.49/aud/audReport
	protected static function genDetailHtml()
	{
		$startDay =  date('Y-m-d' , strtotime('-7 day'));
                #$endDay =  date('Y-m-d' , strtotime('0 day'));
                $endDay =  date('Y-m-d H:i:s' , strtotime('0 day'));
		$columns = array( '#' , 'VALID', 'FAB_SITE' , 'LOGON_TIME' , 'OSUSER' , 'CURRENT_USER' , 'HOST' , 'TERMINAL' , 'IP' );
	
		$html = '<h2>Audit Detail : ( '.$startDay . ' ~ ' . $endDay .' ) </h2><table class="table table-striped" id="sortDetailTable" ><thead><tr>' ; 

		foreach($columns as $col){
	
			$html = $html . '<th scope="col" class="text-primary" >'.$col.'</th> ' ; 

		}

		$html = $html . '</tr></thead><tbody>';	

		$logonRecord = LogonRecordModel::whereBetween('LOGON_TIME' , [$startDay , $endDay])->whereRaw(" ( VALID = 'N' or DOC_NO is not null )")->orderBy('LOGON_TIME' , 'desc')->get() ; 
		$cnt = 1 ; 

		foreach($logonRecord as $row){


			$temp = "<tr><th scope=\"row\">".$cnt++."</th><td class=\"text-danger\" > $row->VALID </td><td> $row->FAB_SITE </td><td> $row->LOGON_TIME </td><td> $row->OSUSER </td><td> $row->CURRENT_USER </td><td> $row->HOST </td><td> $row->TERMINAL </td><td> $row->IP </td></tr>";	
			$html = $html . $temp ; 
		}

		$html = $html . '</tbody></table>' ; 

		#print($html . "\n");

		return $html ; 

	}


		//create redmine issue for web api 
		//args [  PROJECT_ID  , TITLE , DESCRIPTION ]
		//example curl -v -X POST -d 'PROJECT_ID=2&TITLE=TEST12&DESCRIPTION=i am test &ASSIGN_ID=5' http://192.168.111.49/aud/postLogonRecord
		public static function logonRecordProcess(Request $request)
		{

		$projectId = env('REDMINE_HOST_PROJECT_ID');

		 Logger::info($request , __FILE__ , __LINE__ );
		 $clientIP = $request->ip();

		 Logger::info("ip = " . $clientIP , __FILE__ , __LINE__ );


		$keys = $request->keys();
    // $keys数组包含POST请求中的所有参数键

		$checkResult = self::checkLogonRecordRequest($request);

		


		if($checkResult['statusCode'] === 200) {

			$assignment = '' ; 

			if( is_null( $request->ASSIGN_ID ))
			{
				$assignUser = self::getRedmineUserId($request->ASSIGN_USER);
				$defaultUser = self::getRedmineUserId($request->DEFAULT_USER);

				if(! is_null($assignUser) ){
					$assignment = $assignUser ; 
				}else {
					$assignment = $defaultUser ; 
				}
				
			}else {
				$assignment = $request->ASSIGN_ID ; 
			}


			Logger::info("ASSIGN_ID = " . $request->ASSIGN_ID , __FILE__ , __LINE__ );
			Logger::info("assignment = " . $assignment , __FILE__ , __LINE__ );

			$desc =  htmlspecialchars_decode( preg_replace('/[[:cntrl:]]/', '', $request->DESCRIPTION ) , ENT_QUOTES);
			$title =  htmlspecialchars_decode( preg_replace('/[[:cntrl:]]/', '', $request->TITLE ) , ENT_QUOTES);

			#$desc = preg_replace('/[[:cntrl:]]/', '', $request-> DESCRIPTION );

			$redmineRes = self::createRedmineIssue($request->PROJECT_ID  , $assignment , $title ,  $desc ,  date('Y-m-d' , strtotime('+3 day') )  , null  ) ; 

			Logger::info("redmineRes = " . $redmineRes , __FILE__ , __LINE__ );

			$redmineId = $redmineRes->get('ID');
			
			

			if( $redmineRes->get('STATUS_CODE') == 400  ){
				$checkResult['statusCode'] = 400 ; 
				$checkResult['reason'] = $redmineRes->get('REASON') ;
			}else {
				$checkResult['reason'] = null ; 
			}

			$rec = new ApiLogsModel;

	                $rec->fill([
	                   'IP' => $clientIP ,
	                   'ACTION' => 'create_redmine',
	                   'DESCRIPTION' => 'redmine id = ' . $redmineId . ' reason = ' . $checkResult['reason']  .  ' PROJECT_ID = ' . $request->PROJECT_ID . ' ; ' . ' TITLE = ' . $title . ' ; ' . ' ASSIDN_ID = ' . $assignment . ' ; ' . ' DESC = ' . $desc ,
	                   'STATUS' => $redmineRes->get('STATUS_CODE') == 200  ? 'SUCCESS' : 'FAIL'  

	                ]);

 	               $rec->save();
		}



         return json_encode([
                        'statusCode' => $checkResult['statusCode'],
                        'reason' => $checkResult['reason']
                    ] , JSON_UNESCAPED_UNICODE );



		}

		//check postLogonRecord args 
		protected static function checkLogonRecordRequest(Request $request)
		{
		    $requiredFields = [
			'PROJECT_ID',
		        'TITLE',
			'DESCRIPTION'
		    ];
		    $missingFields = array_filter($requiredFields, function ($field) use ($request) {
		        return is_null($request->$field);
		    });
		
		    $statusCode = count($missingFields) > 0 ? 400 : 200;
		    $reason = count($missingFields) > 0 ? implode('; ', $missingFields) . ' is missing' : 'parameter check OK';

		    return compact('statusCode', 'reason');
		}

	// cahnge redmine status and check expire redmine 
	public static function processRedmine ()
	{
			$project_id = env('REDMINE_LOGON_PROJECT_ID');
			self::checkRedmineStatus($project_id);
			self::remindExpireIssue($project_id);
			$project_id = env('REDMINE_VIOLATION_LOGON_PROJECT_ID');
			self::checkRedmineStatus($project_id);
			self::remindExpireIssue($project_id);
			$project_id = env('REDMINE_HOST_PROJECT_ID');
			self::checkRedmineStatus($project_id);
			self::remindExpireIssue($project_id);
	}


	// check mysql table ( logon_record ) to create redmine 
	public static function createAuditRedmine( )
	{

		$limitDay =  date('Y-m-d' , strtotime('-3 day'));


		$logonRecordInfo = LogonRecordModel::whereRaw("LOGON_TIME >=  $limitDay")->where('DOC_NO' , null )->whereRaw("ACTION like '%REDMINE%'") ;


		$users = clone $logonRecordInfo ; 
		$users = $users->select('OSUSER')->distinct()->get();



		foreach ($users as $user){

			$logonByFabUser = clone $logonRecordInfo ; 
			$logonByFabUser = $logonByFabUser->select('FAB_SITE' , 'CURRENT_USER' , 'OSUSER')->where('OSUSER' , $user->OSUSER)->distinct()->get() ; 

		
			foreach( $logonByFabUser as $fabLogon ){
			

				Logger::info("createAuditRedmine : " . $fabLogon->FAB_SITE . "   " . $fabLogon->CURRENT_USER . "   " . $fabLogon->OSUSER  , __FILE__ , __LINE__ );

				$logonInfoTemp = clone $logonRecordInfo ; 
				$logonInfoTemp = $logonInfoTemp->select('*')->where('FAB_SITE' , $fabLogon->FAB_SITE )->where('CURRENT_USER' , $fabLogon->CURRENT_USER)->where('OSUSER' , $fabLogon->OSUSER)->distinct()->get() ; 

				$redmineDesc = self::genRedmineTable( $logonInfoTemp );


				#print("redmineDesc" . $redmineDesc  . "\n" ) ; 

				$userInfos = $logonInfoTemp->first();

				
				Logger::info("createAuditRedmine : " . $userInfos->OSUSER . "   " . $userInfos->HOST . "   " . $userInfos->TERMINAL . "   " .  $userInfos->IP  , __FILE__ , __LINE__ );

					$resUser = self::checkVisEraUser( $userInfos->OSUSER , $userInfos->HOSTt , $userInfos->TERMINAL , $userInfos->IP ) ; 

					$userId = $resUser->get('userId');
					$userName = $resUser->get('userName');

					if(is_null ($userId) )
					{
						$userId = env('REDMINE_DB_LOGON_DEFAULT_ASSIGN_ID');
						$userName = $userInfos->OSUSER ; 
					}

						if( !is_null($userId) ){
						Logger::info("user = " .  $userName . "  user_id = " . $userId   , __FILE__ , __LINE__ ) ; 


							
							$temp = $logonInfoTemp->first();

							Logger::info("VALID = " .  $fabLogon   , __FILE__ , __LINE__ ) ;
							
							$subject = $fabLogon->FAB_SITE.' - logon audit ( '. $fabLogon->CURRENT_USER .' )  -- '. $userName ; 

							$redmineWatcher = null ; 

							if($temp->VALID == 'N'){
								$projectId=env('REDMINE_VIOLATION_LOGON_PROJECT_ID') ;
								$subject = '[違規登入]' . $subject ; 
								$redmineWatcher = self::getRedmineUserId( env('REDMINE_WATCHER') ) ; 

							}else{
								$projectId=env('REDMINE_LOGON_PROJECT_ID') ;
								$redmineWatcher = null ; 
							}

							Logger::info("project_id = " . $projectId   , __FILE__ , __LINE__ ) ;

							$redmineRes = self::createRedmineIssue($projectId , $userId , $subject   ,  $redmineDesc , date('Y-m-d' , strtotime('+3 day') ) , $redmineWatcher );	


							$redmineId = $redmineRes->get('ID');


							$logonInfoUpdate = clone $logonRecordInfo ;
							$logonInfoUpdate->where('FAB_SITE' , $fabLogon->FAB_SITE )->where('CURRENT_USER' , $fabLogon->CURRENT_USER)->where('OSUSER' , $fabLogon->OSUSER)->update(['DOC_NO' => $redmineId ]);

						}



				}


			}
		


		}

		//audit redmine desc sample 
		protected static function genRedmineTable( $logonRecord )
		{
			$desc = "Dear Sir , \\r\\n 此為近期登入 Oracle 的紀錄，\\r\\n 請登入後 ( 帳密同 AD ) ，\\r\\n利用下方編輯留下登入該使用者的原因做為日後稽核使用，謝謝 \\r\\n\\r\\n" ; 
			$redmineTable = $desc . "|_.FAB_SITE|_.LOGON_TIME|_.OSUSER|_.CURRENT_USER|_.HOST|_.TERMINAL|_.IP|" . "\\r\\n";
			

			foreach($logonRecord as $row){
				$redmineTable=$redmineTable.'|'. $row->FAB_SITE.'|'.$row->LOGON_TIME.'|'.$row->OSUSER.'|'.$row->CURRENT_USER.'|'.str_replace('\\' , '' , $row->HOST).'|'.$row->TERMINAL.'|'.$row->IP.'|'."\\r\\n" ; 
			}

			return  $redmineTable ; 

		}
		
		// send Mail function 
		protected static function sendMail( $fromUser , $toUsers , $ccUsers ,  $subject , $message)
		{

			$headers = "From: $fromUser"."\r\n" .
			"MIME-Version: 1.0"."\r\n" .
			"Content-type: text/html; charset=UTF-8"."\r\n".
			"Cc: $ccUsers \r\n";



			mail($toUsers,$subject,$message,$headers);

		}



		//edit Audit logon server list / edit mysql table( server_list )
		public static function deleteServer( $id )
		{
			$server = ServerListModel::where('ID' , $id)->get();

			print("id $id info \n");

			Logger::info("id $id info" , __FILE__ , __LINE__ ) ;

			foreach($server as $row){
			  #print ("$row->fab_site $row->sid  $row->ip:$row->port  $row->username    \n");
			  printf ("%-10s %-15s  %-10s  %-15s  %-8s  %-20s \n\n"  , $row->id ,  $row->FAB_SITE ,  $row->SID ,  $row->IP , $row->PORT , $row->USERNAME );
			}
			
			$server = ServerListModel::where('ID' , $id);
			$server->delete();
			
			print("id $id deleted ! \n");

			Logger::info("id $id deleted ! \n" , __FILE__ , __LINE__ ) ;

		}

		//show  Audit logon server list
		public static function showServerList()
		{
			$rows = ServerListModel::all();
			  printf ("%-10s %-15s  %-10s  %-15s  %-8s  %-20s \n" , 'id' ,  'fab_site' ,  'sid' ,  'ip' , 'port' , 'username' );
			  printf("%'-75s\n" , '');
			foreach($rows as $row){
			  #print ("$row->fab_site $row->sid  $row->ip:$row->port  $row->username    \n");
			  printf ("%-10s %-15s  %-10s  %-15s  %-8s  %-20s \n"  , $row->id ,  $row->FAB_SITE ,  $row->SID ,  $row->IP , $row->PORT , $row->USERNAME );
			}
		}

		// get logon info to mysql ( server_list)
		public static function startAudit()
		{

			$serverInfos = ServerListModel::all();
			
			foreach($serverInfos as $serverInfo)
			{

			   try{
				$dbPassword = $serverInfo->PASSWORD ;
                                $dbAddress = $serverInfo->IP . ':'. $serverInfo->PORT . '/' . $serverInfo->SID ;

                        #       print(date('Y-m-d g:i:s')." connect to $serverInfo->fab_site : $dbAddress \n");
				Logger::info(" connect to $serverInfo->FAB_SITE : $dbAddress " , __FILE__ , __LINE__ ) ; 
                                $conn = oci_connect( $serverInfo->USERNAME , $dbPassword , $dbAddress ,'zht16big5');
                                if (!$conn) {
                                   $e = oci_error();
                                   Logger::error($e , __FILE__ , __LINE__);
                                exit;
                                }

                        #       print (date('Y-m-d g:i:s')." get logon info \n");
                                $rows = oci_parse($conn, self::getAuditSql() );
                                if(!oci_execute($rows)){
                                   $e = oci_error();
                                   Logger::error($e , __FILE__ , __LINE__);
                                   exit;
                                 }



                                while ($row = oci_fetch_array($rows, OCI_ASSOC+OCI_RETURN_NULLS))
                                {


                                        $rec = new LogonRecordModel;
                                        $rec->FAB_SITE = $serverInfo->FAB_SITE ;
                                        $rec->FUNCTION = 'db_logon_monitor';
                                        foreach ($row as $col=>$item) {
                                        $rec[$col] = mb_convert_encoding ($item,"utf-8","big5");
                                        }

                                        #$exists = self::checkInWhite( $rec->fab_site , $rec->osuser , $rec->current_user , $rec->host , $rec->terminal , $rec->ip );
                                        $logonStatus = self::checkAuditList( $rec->FAB_SITE , $rec->OSUSER , $rec->CURRENT_USER , $rec->HOST , $rec->TERMINAL , $rec->IP );

                                          $rec->VALID = $logonStatus->get('VALID') ;
                                          $rec->ACTION = $logonStatus->get('ACTION') ;
                                          $rec->RANK = $logonStatus->get('RANK') ;
                                          $rec->IS_URGENT = $logonStatus->get('IS_URGENT') ;

					  preg_match( "(REPADMIN)" ,  $logonStatus->get('ACTION') , $matches);

					  if( count($matches) == 0 ){
					  	$rec->STATUS = 'PASS';
					  }


                                        $rec->save();

                                }


                                oci_free_statement($rows);
                                oci_close($conn);

			   }catch(Exception $e){
				Logger::error($serverInfo->fab_site . "\n" . $e , __FILE__ , __LINE__);
			   }

			}



			try {
				self::createAuditRedmine();

            } catch (Exception $e) {
                Logger::error( $e , __FILE__ , __LINE__);
            } 
		
		}

		//edit Audit logon server list / edit mysql table( server_list )
		public static function addServer($fab_site , $ip , $port , $sid , $username , $password)
		{

		      #$encryWord = env('ENCRYPTION_WORD');
		      #$encryMethod = env('ENCRYPTION_METHOD');

		      $row = new ServerListModel;
		      $row->FAB_SITE = $fab_site;
		      $row->IP = $ip ; 
		      $row->PORT = $port ; 
		      $row->SID = $sid ; 
		      $row->USERNAME = $username;
		      $row->PASSWORD = $password;
		      $row->save();



		}



	protected static function createRedmineIssue($projectId , $user_id , $subject ,  $desc , $date , $redmineWatcher )
	{
		try{
	                $apiUrl=env('REDMINE_API_URL') ;
			$trackerId=env('REDMINE_TRACKER_ID') ;
			$redmineApiKey=env('REDMINE_API_KEY');


			$redmineId = self::getRedmineWithSubject($subject , $projectId);

			#Logger::info('redmine id = ' . $redmineId . "\n"  , __FILE__ , __LINE__ );
			#Logger::info('subject = ' . $subject . "\n"  , __FILE__ , __LINE__ );
			
			$subject = self::replaceSupplyChar($subject) ; 
			$desc = self::replaceSupplyChar($desc) ; 

			if($redmineId != -1 ){
				return collect(['ID' => $redmineId , 'STATUS_CODE' => 400 , 'REASON' => 'duplicate topic in 120 mins ' ]);
                        }else {


	                        $json = '{"issue": {"project_id": '.$projectId.', "tracker_id": '.$trackerId.' ,"subject":"'.$subject.'",   "priority_id": 2  , "description": "'.$desc.'" , "due_date": "'.$date.'" , "assigned_to_id":"'.$user_id.'" , "watcher_user_ids":"'.$redmineWatcher.'" }}' ;

        	                $api = $apiUrl . "/issues.json";
                	        $ch = curl_init();
	                        curl_setopt($ch, CURLOPT_URL, $api);
	                        curl_setopt($ch, CURLOPT_POST, true);
	                        curl_setopt($ch, CURLOPT_POSTFIELDS, $json );
	                        curl_setopt($ch, CURLOPT_RETURNTRANSFER , true );
	                        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
	                                        'X-Redmine-API-Key: ' .  $redmineApiKey ,
	                                        'Content-Type: application/json')
	                                        );
	                        $output = curl_exec($ch);
	                        curl_close($ch);

	                        $res = json_decode($output) ;

	                        Logger::info("createRedmineIssue : " . $output , __FILE__ , __LINE__ );



	                        if(isset($res->{'issue'}->{'id'})){
					return collect(['ID' => $res->{'issue'}->{'id'} , 'STATUS_CODE' => 200 , 'REASON' => null  ]);
	                        }else {
					return collect(['ID' => null , 'STATUS_CODE' => 400,  'REASON' =>  $output ]);
	                        }

                        }


		}catch(Exception $e){
			Logger::error($e , __FILE__ , __LINE__ ) ; 
		}
		

	}


	
	protected static function getRedmineIssueLastNote( $redmineId  ){


	if (empty($redmineId) ) {
        return collect(['NOTE' => null , 'CREATE_TIME' => null ]);
	}

	set_error_handler(
                        function ($severity, $message, $file, $line) {
                                return null ;
                        }
                );

	$cacheKey = 'redmine_issue_last_note_' . $redmineId;
	$cacheTtl = 600; // 1 hour
	$cache = Cache::get($cacheKey);

	if ($cache) {
		return $cache;
	}

	$apiUrl = env('REDMINE_API_URL');
	$api = $apiUrl . '/issues/' . $redmineId . '.json?include=journals&limit=1&sort=journals.created_on:desc&key=' . env('REDMINE_API_KEY');
	$result = file_get_contents($api);
	$json = json_decode($result);
	$journals = $json->issue->journals;
	$assigned_to_id = $json->issue->assigned_to->id;
    $assigned_to_name = $json->issue->assigned_to->name;

    $assigned_name_list = explode(' ', $assigned_to_name) ;

    $assigned_name = '' ;

    if(count($assigned_name_list))
    {
        $assigned_name = $assigned_name_list[1] ;
    }else
    {
        $assigned_name = $assigned_name_list[0] ;
    }

	foreach (array_reverse($journals) as $journal) {
		if ( strlen($journal->notes) != 0 && $journal->user->id == $assigned_to_id ) {
			#$note = $journal->notes;
			#Cache::put($cacheKey, $note, $cacheTtl);
			#return $note;
			$datetime = new DateTime($journal->created_on);
            $datetime->setTimezone(new DateTimeZone('Asia/Taipei'));

			$note = $journal->notes;
            #$noteInfo = collect(['NOTE' => $note , 'CREATE_TIME' => $datetime->format('Y-m-d H:i:s') ]) ;
			$noteInfo = collect(['NAME' => $assigned_name , 'NOTE' => $note , 'CREATE_TIME' => $datetime->format('Y-m-d H:i:s') ]) ;
            #$noteInfo = collect(['NOTE' => $note , 'CREATE_TIME' => str_replace('Z' , '' , str_replace('T' , ' ' , $journal->created_on )) ]) ;
            Cache::put($cacheKey, $noteInfo, $cacheTtl);
            return $noteInfo;

		}
	}

			return collect([ 'NAME' => $assigned_name  , 'NOTE' => null , 'CREATE_TIME' => null ]);

	set_error_handler();


	}


	protected static function getRedmineUserId( $name )
	{
		$apiUrl = env('REDMINE_API_URL');
		$apiKey = env('REDMINE_API_KEY');
	 	$api = $apiUrl . '/users.json?name=' . $name;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $api);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Redmine-API-Key: ' . $apiKey));

		$result = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode == 200) {
			$json = json_decode($result);
			if (count($json->{'users'}) != 0) {
			return $json->{'users'}[0]->{'id'};
			}
		}
		return null;
	
	}

	

	protected static function checkAuditList( $fab_site , $osuser , $current_user , $host , $terminal , $ip )
	{
		
		$debug_flag = false ; 

		preg_match( "/v\d{5}$/" , $osuser , $matchOsuser);

        if(count($matchOsuser) == 0 ){
            $responseIP = file_get_contents('http://192.168.111.49/rsl/ip2account?ip=' . $ip  );


             preg_match( "/v\d{5}$/" , $responseIP , $ipMatch);

            if( count($ipMatch) > 0  )
            {
				Logger::info( "Logon Info : re-assign osuser from $osuser to  $responseIP in ip = $ip  " , __FILE__ , __LINE__ );
                $osuser = $responseIP ;
				
            }
        }


		Logger::info( "Logon Info : ". "$fab_site , $osuser , $current_user , $host , $terminal , $ip " , __FILE__ , __LINE__ );



			$res = AuditCheckListModel::query()
                                        ->whereRaw('( fab_site = "*" or ? like fab_site )' , $fab_site)
                                        ->whereRaw('( osuser = "*" or ? like osuser )' , $osuser)
                                        ->whereRaw('( `current_user` = "*" or ? like `current_user` )' , $current_user)
                                        ->whereRaw('( host = "*" or ? like host )' , $host)
                                        ->whereRaw('( terminal = "*" or ? like terminal )' , $terminal)
                                        ->whereRaw('( ip = "*" or ? like ip )' , $ip)
					#->orderByRaw('case when action = "MAIL" then 0 else 1 end ')
					->orderBy('RANK')
                                        #->take(1)
					->get();

			if($debug_flag){
				foreach($res as $row){
					print ("$row->FAB_SITE  $row->OSUSER  $row->CURRENT_USER $row->HOST $row->TERMINAL $row->IP $row->DESCRIPTION  \n");
	                        }

			}


			if($res->count() >= 1 ){
			  $temp = $res->first();
			  return collect(['VALID' => $temp->VALID , 'RANK' => $temp->RANK , 'ACTION' => $temp->ACTION , 'IS_URGENT' => $temp->IS_URGENT ]);
			}else{
			  return collect(['VALID' => 'N' , 'RANK' => 0 , 'ACTION' => 'MAIL' , 'IS_URGENT' => 'Y' ]);
			}

		

	}



	protected static function getAuditSql()
	{
		return "with T1 as
                (
                    select trunc(sysdate , 'hh') + (floor(to_char(sysdate , 'mi') / 10)* 10) / 1440 audit_datetime from dual
                )select to_char(a.LOGON_TIME , 'yyyy-mm-dd hh24:mi:ss') LOGON_TIME , a.OSUSER , a.CURRENT_USER , a.HOST , a.TERMINAL , a.IP from sys.audit_logon a , T1 b
                where a.LOGON_TIME between b.audit_datetime - (10/1440) and audit_datetime";
	}


	protected static function findPersonInfo( $employeeNo )
	{

		try {
		    $employee = Cache::remember('employee:'.$employeeNo, 1440 , function () use ($employeeNo) {
		        return VeEmployeeModel::query()->where('EMPLOYEE_NO', str_replace('v', '', $employeeNo))->firstOrFail();
		    });
		} catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
		    return collect([ 'EMPLOYEE_NO' => null , 'EMAIL' => null , 'REDMINE_ID' => null , 'BOSS_EMPLOYEE_NO' => null , 'BOSS_EMAIL' => null , 'BOSS_REDMINE_ID' => null ]);
		}

		if($employee->DEPARTMENT_LEADER == '蔡煜？') {
        		$employee->DEPARTMENT_LEADER = '蔡煜坤';
    		}

		$boss = Cache::remember('boss:'.$employeeNo, 1440, function () use ($employee) {
		    return $employee->boss;
		});
		return collect([
		    'EMPLOYEE_NO' => $employeeNo,
		    'EMAIL' => $employee->EMAIL . '@viseratech.com',
		    'REDMINE_ID' => self::getRedmineUserId($employeeNo),
		    'BOSS_EMPLOYEE_NO' => $boss ? 'v' . $boss->EMPLOYEE_NO : null,
		    'BOSS_EMAIL' => $boss ? $boss->EMAIL . '@viseratech.com' : null,
		    'BOSS_REDMINE_ID' => $boss ? self::getRedmineUserId('v' . $boss->EMPLOYEE_NO) : null,
		]);

	}


	protected static function checkRedmineStatus ($project_id) 
	{
		// 設定 Redmine API 存取權限的 key
		$api_key = env('REDMINE_API_KEY');

		// 設定 Redmine 網址
		$redmine_url = env('REDMINE_API_URL') ;

		// 設定要查詢的 Redmine project ID 和 status ID
		#$project_id = env('REDMINE_LOGON_PROJECT_ID');
		$status_id = '!5';

		// 設定要變更成的 status ID
		$new_status_id = 5;
		$new_done_ratio = 100;


		$limit = 100; // 每次取得 100 個 ISSUE
		$offset = 0; // 從第一筆 ISSUE 開始取得

		// 取得符合條件的 ISSUE 清單
		$issues_url = $redmine_url . '/issues.json?project_id=' . $project_id . '&status_id=' . $status_id . '&limit=' . $limit . '&offset=' . $offset;
		$issues_data = json_decode(file_get_contents($issues_url, false, stream_context_create([
		    'http' => [
		        'method' => 'GET',
		        'header' => [
		            'Content-Type: application/json',
		            'X-Redmine-API-Key: ' . $api_key
		        ]
		    ]
		])), true);

		// 遍歷 ISSUE 清單，檢查每個 ISSUE 是否有指派的使用者留下的留言
		foreach ($issues_data['issues'] as $issue) {
		    $issue_id = $issue['id'];
		    $issue_url = $redmine_url . '/issues/' . $issue_id . '.json?include=journals';
		    $issue_data = json_decode(file_get_contents($issue_url, false, stream_context_create([
		        'http' => [
		            'method' => 'GET',
		            'header' => [
		                'Content-Type: application/json',
		                'X-Redmine-API-Key: ' . $api_key
		            ]
		        ]
		    ])), true);
		    foreach ($issue_data['issue']['journals'] as $journal) {
		        $notes = $journal['notes'];
		        $user_id = $journal['user']['id'];
		        $assigned_to_id = $issue['assigned_to']['id'];
		        // 如果留言是由指定使用者留下的，就將 ISSUE 的 status_id 改變為新的狀態
		        if ( ($user_id === $assigned_to_id && !empty($notes)) ) {
		            $update_issue_url = $redmine_url . '/issues/' . $issue_id . '.json';
		            $update_issue_data = [
		                'issue' => [
		                    'status_id' => $new_status_id,
				    'done_ratio' => $new_done_ratio
				    #'notes' => 'Assigned user has replied. Closing this issue.'
		                ]
		            ];
		            $update_issue_options = [
		                'http' => [
		                    'method' => 'PUT',
		                    'header' => [
		                        'Content-Type: application/json',
		                        'X-Redmine-API-Key: ' . $api_key
		                    ],
		                    'content' => json_encode($update_issue_data)
		                ]
		            ];
		            $update_issue_context = stream_context_create($update_issue_options);
		            $update_issue_result = file_get_contents($update_issue_url, false, $update_issue_context);
		            $update_issue_data = json_decode($update_issue_result, true);
			    Logger::info('Issue ID: ' . $issue_id . ' has been updated to status ID: ' . $new_status_id . ' and done ratio: ' . $new_done_ratio , __FILE__ , __LINE__ );
		            break;
		        }
		    }

			$assigned_to_id = $issue['assigned_to']['id'];

			if( !is_null( self::getTimeSpendComment($issue_id , $assigned_to_id ))  )
			{
				 	$update_issue_url = $redmine_url . '/issues/' . $issue_id . '.json';
                    $update_issue_data = [
                        'issue' => [
                            'status_id' => $new_status_id,
                    		'done_ratio' => $new_done_ratio
                    #'notes' => 'Assigned user has replied. Closing this issue.'
                        ]
                    ];
                    $update_issue_options = [
                        'http' => [
                            'method' => 'PUT',
                            'header' => [
                                'Content-Type: application/json',
                                'X-Redmine-API-Key: ' . $api_key
                            ],
                            'content' => json_encode($update_issue_data)
                        ]
                    ];
                    $update_issue_context = stream_context_create($update_issue_options);
                    $update_issue_result = file_get_contents($update_issue_url, false, $update_issue_context);
                    $update_issue_data = json_decode($update_issue_result, true);
                Logger::info('Issue ID: ' . $issue_id . ' has been updated to status ID: ' . $new_status_id . ' and done ratio: ' . $new_done_ratio , __FILE__ , __LINE__ );

			}
		}
	}


	protected static function remindExpireIssue ( $project_id ) 
	{
		$url = env('REDMINE_API_URL');
		$projectId =  $project_id ;
		$statusId = 1;
		$key = env('REDMINE_API_KEY');
		$currentDate = date("Y-m-d");
		$offset = 0;  // 設置 offset 初始值為 0
		$limit = 1000; // 設置 limit 值為一個較大的值，這裡設置為 100


		// Set up cURL to get issues
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "$url/issues.json?project_id=$projectId&status_id=$statusId&due_date=%3C=$currentDate&include=journals&offset=$offset&limit=$limit");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		    "Content-Type: application/json",
		    "X-Redmine-API-Key: $key"
		));
		$result = curl_exec($ch);
		curl_close($ch);
		// Parse the JSON response
		if(strlen($result) == 0 ){
			return ; 
		}		

		$issues = json_decode($result, true)["issues"];
		

		
		foreach ($issues as $issue) {
		    $issueId = $issue["id"];
		    #$notes = $issue["journals"];
		    $notes = self::getIssueJournals($issueId);
		    $assignedTo = $issue["assigned_to"];
		
		    // Check if there are any notes left by the assigned_to user
		    $assignedToNotes = array_filter($notes, function($note) use ($assignedTo) {
		        return $note["user"]["id"] == $assignedTo["id"];
		    });

		    // If there are no assigned_to notes, update the issue note
		    if (count($assignedToNotes) == 0) {
		        $ch = curl_init();
		        $postData = array(
		            'issue' => array(
		                'notes' => '此單已超過完成期限尚未更新，請盡速更新，謝謝'
		            )
		        );
		        curl_setopt($ch, CURLOPT_URL, "$url/issues/$issueId.json");
		        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
		        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
		        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		            "Content-Type: application/json",
		            "X-Redmine-API-Key: $key"
		        ));
		        $result = curl_exec($ch);
		        curl_close($ch);
		    }
		}
	}


	protected static  function getIssueJournals($issueId) {
    		$url = env('REDMINE_API_URL');
    		$key = env('REDMINE_API_KEY');
    		$limit = 1; // 設置 limit 值為一個較大的值，這裡設置為 1
    		$sort = "journals.created_on:desc";

    		$ch = curl_init();
    		curl_setopt($ch, CURLOPT_URL, "$url/issues/$issueId.json?include=journals&limit=$limit&sort=$sort");
    		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        		"Content-Type: application/json",
        		"X-Redmine-API-Key: $key"
    		));
    		$result = curl_exec($ch);
    		curl_close($ch);

    		// Parse the JSON response and get journals
    		$journals = json_decode($result, true)["issue"]["journals"];
    		return $journals;
	}	


	protected static function getRedmineWithSubject($subject , $projectId )
        {
		#echo $subject . "\n";
                $redmineUrl = env('REDMINE_API_URL');
                $apiKey = env('REDMINE_API_KEY');
                $invalidRange = '' ;

		if($projectId == 2 ){
			$invalidRange = '-' . env('REDMINE_HOST_INVALID_RANGE_MINUTE') . ' minutes' ;
		}else {
			$invalidRange = '-' . env('REDMINE_INVALID_RANGE_MINUTE') . ' minutes' ;
		}

                // 要搜索的標題關鍵字
                $keyword = $subject;

                // 設定搜尋的參數
                $params = [
                    'subject' => $keyword,
                    'created_on' => '>=' . date('Y-m-d\TH:i:s', strtotime($invalidRange)),
                    'limit' => 1, // 只返回一筆結果
                    'status_id' => '*', // 只返回一筆結果
		    'project_id' => $projectId, // 指定專案 ID
                ];

                // 建立cURL請求
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $redmineUrl . '/issues.json?' . http_build_query($params),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'X-Redmine-API-Key: ' . $apiKey,
                    ],
                ]);

                // 執行API請求
                $response = curl_exec($curl);
                $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		#echo  $response . "\n" ;
		#echo  $status . "\n" ;

                // 檢查請求是否成功
                if ($status === 200) {
                    $data = json_decode($response, true);
                    $issues = $data['issues'];
                    // 檢查是否有返回結果
                    if (count($issues) > 0) {
						Logger::info('Duplicate redmine subject ' . $subject . ' in ' . $invalidRange . ' mins '  , __FILE__ , __LINE__ );
                        $issue = $issues[0];
                        // 在這裡處理返回的結果
                        return $issue['id'];
                    } else {
                        return -1;
                    }
                } else {
			return -1 ;
                }

                // 關閉cURL資源
                curl_close($curl);

        }
		


		// For the weekly Tuesday Audit summary email
		protected static function genLogonExpireMail()
		{
			$url = env('REDMINE_API_URL');
	        $projectIds =  [1,3] ;
    	    $statusId = 1;
	        $key = env('REDMINE_API_KEY');
	        $currentDate = date("Y-m-d" , strtotime('-1 days') );
    	    $offset = 0;  // 設置 offset 初始值為 0
	        $limit = 1000; // 設置 limit 值為一個較大的值，這裡設置為 100	


			$html = "<h1>List of Overdue Unreported Cases:<h1>" ;
			$html = $html . "<table border=\"1\" style=\"border-collapse:collapse\" borderColor=\"gray\">";
			$html = $html . "<th scope=\"col\" bgcolor=\"#d3f0ac\">FAB_SITE</th><th scope=\"col\" bgcolor=\"#d3f0ac\">LOGON_TIME</th><th scope=\"col\" bgcolor=\"#d3f0ac\">OSUSER</th><th scope=\"col\" bgcolor=\"#d3f0ac\">CURRENT_USER</th><th scope=\"col\" bgcolor=\"#d3f0ac\">HOST</th><th scope=\"col\" bgcolor=\"#d3f0ac\">IP</th><th scope=\"col\" bgcolor=\"#d3f0ac\">DISPLAY_NAME</th><th scope=\"col\" bgcolor=\"#d3f0ac\">REASON</th><th scope=\"col\" bgcolor=\"#d3f0ac\">TOPIC</th>";

				

			foreach ($projectIds as $projectId) 
			{
		    	// Set up cURL to get issues
	        	$ch = curl_init();
	 	        curl_setopt($ch, CURLOPT_URL, "$url/issues.json?project_id=$projectId&status_id=$statusId&due_date=%3C=$currentDate&offset=$offset&limit=$limit");
		        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    	    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
					"Content-Type: application/json",
					"X-Redmine-API-Key: $key"
	        	));
	        	$result = curl_exec($ch);
	        	curl_close($ch);
	        	// Parse the JSON response
	        	if(strlen($result) == 0 ){
	            	return ;
	        	}


				$issues = json_decode($result, true) ; 

			
				foreach ($issues['issues'] as $issue) 
				{
    	              // 取得 id 和 assign_to 欄位的值
					$id = $issue['id'];

					$reason =  self::getRedmineIssueLastNote( $id )  ;

					$html = $html . self::genMailFromLogonInfo( $id , $reason );
				
    	        }
			}

			 $html = $html . "</table>";


			return $html ; 
			
		}

		//For the weekly Tuesday Audit summary email
		protected static function genViolationMail( $startDate , $endDate )
        { 
                $redmineBaseUrl = env('REDMINE_API_URL');
                
                $apiKey = env('REDMINE_API_KEY');
                $projectId = env('REDMINE_VIOLATION_LOGON_PROJECT_ID'); // 替換為你的目標專案 ID


                #$currentDayOfWeek = date('N');

                #$startDate = date('Y-m-d', strtotime("-" . ($currentDayOfWeek + 6) . " days")) ;
                #$startDate = '2023-07-22' ;
                #$endDate = date('Y-m-d', strtotime("-" . ($currentDayOfWeek - 1 ) . " days")) ;


                $html = "<h1>Audit Detail:<h1>" ;
                $html = $html . "<table border=\"1\" style=\"border-collapse:collapse\" borderColor=\"gray\">";
                $html = $html . "<th scope=\"col\" bgcolor=\"#d3f0ac\">FAB_SITE</th><th scope=\"col\" bgcolor=\"#d3f0ac\">LOGON_TIME</th><th scope=\"col\" bgcolor=\"#d3f0ac\">OSUSER</th><th scope=\"col\" bgcolor=\"#d3f0ac\">CURRENT_USER</th><th scope=\"col\" bgcolor=\"#d3f0ac\">HOST</th><th scope=\"col\" bgcolor=\"#d3f0ac\">IP</th><th scope=\"col\" bgcolor=\"#d3f0ac\">DISPLAY_NAME</th><th scope=\"col\" bgcolor=\"#d3f0ac\">REASON</th><th scope=\"col\" bgcolor=\"#d3f0ac\">TOPIC</th>";



                $queryParams = array(
                        'project_id' => $projectId,
                        'status_id' => '*',
                        'created_on' => '><' . $startDate . '|' . $endDate   // 注意 %3E 為 URL 編碼的 >
                    );

                    // 構建完整的 API URL 包含查詢參數
                    $url = "$redmineBaseUrl/issues.json?" . http_build_query($queryParams);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'X-Redmine-API-Key: ' . $apiKey
                    ));

                    $response = curl_exec($ch);

                    if (curl_errno($ch)) {
                        echo 'Error: ' . curl_error($ch);
                        exit;
                    }

                    curl_close($ch);


                $responseData = json_decode($response, true);

                // 檢查 API 回傳的資料是否成功
                if (isset($responseData['issues'])) {
                    // 迭代處理每個 issue
                    foreach ($responseData['issues'] as $issue) {
                        // 取得 id 和 assign_to 欄位的值
                        $id = $issue['id'];
                        $assignTo = $issue['assigned_to']['id'];

                        // 輸出 id 和 assign_to
                        #echo "ID: {$id}, Assign To: {$assignTo}\n";
                        $reason =  self::getRedmineIssueLastNote( $issue['id'] )  ;
                        #echo $reason ;
                        $html = $html . self::genMailFromLogonInfo( $issue['id'] ,  $reason );
                    }
                } else {
                    // API 資料錯誤
		     Logger::error('fail get data from redmine api ' , __FILE__ , __LINE__);
                }

                $html = $html . "</table>";



		#$html = $html . 'Summary report  : <a href=http://192.168.111.49/aud/audReport target="_blank">Audit DB logon report </a>';

                return collect(['html' => $html , 'cnt' => count($responseData['issues']) ]) ;
        }


		protected static function genMailFromLogonInfo( $docNo , $reason )
        {

                $logonInfo = LogonRecordModel::query()->where('DOC_NO' , $docNo)->first();

				$html = null ; 

				$note = $reason->get('CREATE_TIME') . ' : <br>'. $reason->get('NOTE') ;
				$assign_name = $reason->get('NAME') ;

                if(isset($logonInfo))
                {
                  $redmineUrl=env('REDMINE_API_URL') . '/issues/' . $logonInfo->DOC_NO;
                  $html = "<tr>";
                  $html = $html . "<td> $logonInfo->FAB_SITE </td><td style=\"white-space: nowrap;\" > $logonInfo->LOGON_TIME </td><td> $logonInfo->OSUSER </td><td> $logonInfo->CURRENT_USER </td><td> $logonInfo->HOST </td><td> $logonInfo->IP </td><td> $assign_name </td><td> $note </td><td><a href=$redmineUrl  target=\"_blank\" > $logonInfo->DOC_NO </a></td>" ;
                  $html = $html . "</tr>" ;

                }



                return $html ;

        }

		//For the weekly Tuesday summary email
		public static function sendViolationMail( $execMode )
		{
            try {
				self::processRedmine();

            } catch (Exception $e) {
                Logger::error( $e , __FILE__ , __LINE__);
            }


			$mailTo = null ; 
			if( strtolower($execMode) == 'prod')
			{
				$mailTo = env('IT_SIRS_MAIL_LIST') ;	
			}else 
			{
				$mailTo = env('IT_SIRS_MAIL_LIST_TEST_MODE') ;
			}


			$currentDayOfWeek = date('N');


			$startDate = date('Y-m-d', strtotime("-" . ($currentDayOfWeek + 5) . " days")) ;

            $endDate = date('Y-m-d', strtotime("-" . ($currentDayOfWeek - 1 ) . " days")) ;

			$mailFrom = env('MAIL_FROM');


			$vioInfo = self::genViolationMail($startDate , $endDate ) ; 

			$htmlContent =  $vioInfo->get('html') ;
            $htmlContent = $htmlContent . '<tr></tr>' ;
            $htmlContent = $htmlContent . '<tr></tr>' ;
            $htmlContent = $htmlContent . '<tr></tr>' ;
            $htmlContent = $htmlContent .  self::genLogonExpireMail()  ;



			Logger::info( 'sendViolationMail:exec mode = ' . $execMode  , __FILE__ , __LINE__ );
            Logger::info( 'sendViolationMail:mailto = ' . $mailTo  , __FILE__ , __LINE__ );
            Logger::info( $htmlContent  , __FILE__ , __LINE__ );
			
				
                if($vioInfo->get('cnt') == 0 ){
                        self::sendMail($mailFrom , $mailTo , '' , '[DBM 稽核通知]非法使用 DB SYSTEM USER 通知(本週無被稽核項目)' , $htmlContent);
                }else {
                        self::sendMail($mailFrom , $mailTo , '' , '[DBM 稽核通知]非法使用 DB SYSTEM USER 通知' , $htmlContent );

                }
				

		}


        public static function checkVisEraUser( $osUser , $host , $terminal , $ip )
        {


            $responseIP = file_get_contents('http://192.168.111.49/rsl/ip2account?ip=' . $ip  );

                    $newHost = substr(str_replace("VISERATECH\\", "", $host),5,5);
                    $newTerminal = substr( $terminal , 5 , 5);

                    preg_match( "/v\d{5}/" , $osUser , $matchOsuser);
                    preg_match( "/v\d{5}/" , $responseIP , $matchIP);
                    preg_match( "/\d{5}/" ,  $newHost , $matchHost);
                    preg_match( "/\d{5}/" ,  $newTerminal , $matchTerminal);

                    $userId = null ;
                    $userName = null ;



                    if( count($matchOsuser) > 0  )
                    {
                          $userId = self::getRedmineUserId( $osUser  );
                          $userName = $osUser ;
                    }elseif( count($matchIP))
                    {
                          $userId = self::getRedmineUserId( $responseIP );
                              $userName = $responseIP ;

                    }
                    elseif( count($matchHost) > 0 )
                    {
                            $userId = self::getRedmineUserId( 'v'.$newHost );
                              $userName = 'v'.$newHost ;
                    }elseif( count($matchTerminal) >0)
                    {
                          $userId = self::getRedmineUserId( 'v'.$newTerminal );
                          $userName = 'v'.$newTerminal ;
                    }

			$deBug = false ;

            if($deBug)
            {
                Logger::info( 'osUser = ' . $osUser . ' host = ' . $host . ' terminal = ' . $terminal . ' ip = ' . $ip  , __FILE__ , __LINE__ );
                Logger::info( 'responseIP = ' . $responseIP   , __FILE__ , __LINE__ );
            }


                    return collect(['userId' => $userId , 'userName' => $userName ]) ;

        }
	

		protected static function getTimeSpendComment( $docNo , $userId )
        {
			if(is_null($userId))
			{
				return null ; 
			}


            $apiUrl = env('REDMINE_API_URL');
            $api = $apiUrl . '/time_entries.json?issue_id=' . $docNo . '&user_id='.$userId.'&sort=created_on:desc&limit=1&key=' . env('REDMINE_API_KEY');
            $result = file_get_contents($api);
            $data = json_decode($result , true);

            if (isset($data['time_entries'][0]['comments'])) {
                $comments = $data['time_entries'][0]['comments'];
                // 在這裡處理 comments
				#Logger::info( 'comment = ' . $comments  , __FILE__ , __LINE__ );
				#Logger::info( 'docNo = ' . $docNo  , __FILE__ , __LINE__ );
				#Logger::info( 'userId = ' . $userId  , __FILE__ , __LINE__ );
                return  $comments;
            } else {
                // 若不存在 comments，可以進行其他處理
                return  null ;
            }

        }

		protected static function replaceSupplyChar( $str )
        {

            if (preg_match_all('/[\x{20000}-\x{2A6DF}]/u', $str, $matches)) {
                foreach ($matches[0] as $match) {
                    $str = str_replace($match, "?", $str);
                }

                return $str ;

            } else {
                return $str ;
            }

        }



}

