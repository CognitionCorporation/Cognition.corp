<?php
	/*
	@license magnet:?xt=urn:btih:0b31508aeb0634b347b8270c7bee4d411b5d4109&dn=agpl-3.0.txt
	
	Copyright (C) 2016 LucFauvel and SabineWren
	
	GNU AFFERO GENERAL PUBLIC LICENSE Version 3, 19 November 2007
	https://www.gnu.org/licenses/agpl-3.0.html
	
	@license-end
	
	* @Description:
	* 1) Connect to DB
	* 2) Prepare statements
	* OUTER LOOP:
	*		3) Query SC-API (all orgs)
	*		INNER LOOP:
	*			4) Sub-query Org (more data)
	*			5) Bind data to statement
	*			6) Execute Database Transactions
	* 7) Close Statements
	* 8) Recluster Tables
	* 9) Close connection
	*/
	
	function didSizeChange(&$SID, &$size, &$connection){
//THIS IS A POSSIBLE SECURITY VULNERABILITY (SQL injection)
//but the input is from the sc-api, not from a regular user
		$rows = $connection->query("SELECT Size FROM tbl_Organizations WHERE SID = UPPER('$SID')");
		$row = $rows->fetch_assoc();
		if($row == null){
			echo "NOT FOUND Org SID = $SID\n";
			return true;
		}
		if($size != $row['Size']){
			echo "UPDATING Org SID = $SID\n";
			return true;//org has changed size
		}
		//echo "Not updating Org SID = $SID\n";
		return false;
	}
	
	//1) Connect to DB
	if( sizeof($argv) < 3){
		echo "Correct usage: php " . $argv[0] . " <db username> <db password>\n";
		exit();
	}
	
	$connection = new mysqli("192.168.0.105",$argv[1],$argv[2], "cognitiondb");
	if( mysqli_connect_errno() ){
		die( "Connection failed: " . mysqli_connect_error() );
	}
	$connection->autocommit(FALSE);//accelerate inserts
	
	//2) Prepare statements
	$prepared_insert_org  = $connection->prepare("INSERT INTO tbl_Organizations (SID, Name, Size, Main, Icon, URL) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE Name = ?, Size = ?, Main = ?, Icon = ?, URL = ?");
	$prepared_insert_name = $connection->prepare("INSERT INTO tbl_OrgNames      (SID, NameUpper)       VALUES (?, ?)    ON DUPLICATE KEY UPDATE NameUpper = ?");
	$prepared_insert_org ->bind_param("sssssssssss", $SID, $Name, $Size, $Main, $Icon, $URL, $Name, $Size, $Main, $Icon, $URL);
	$prepared_insert_name->bind_param("sss",  $SID, $NameUpper , $NameUpper );
	
	$prepared_insert_commits = $connection->prepare("INSERT INTO tbl_Commits(Organization, Commitment) VALUES (?, ?) ON DUPLICATE KEY UPDATE Commitment = ?");
	$prepared_insert_commits->bind_param("sss", $SID, $Commitment, $Commitment);
	
	$prepared_insert_full = $connection->prepare("INSERT INTO tbl_FullOrgs(Organization) VALUES (?) ON DUPLICATE KEY UPDATE Organization = ?");
	$prepared_delete_full = $connection->prepare("DELETE from tbl_FullOrgs WHERE Organization = ?");
	$prepared_insert_full->bind_param("ss", $SID, $SID);
	$prepared_delete_full->bind_param("s", $SID);
	
	$prepared_insert_primary   = $connection->prepare("INSERT INTO tbl_PrimaryFocus  (PrimaryFocus,   Organization) VALUES (?, ?) ON DUPLICATE KEY UPDATE PrimaryFocus = ?");
	$prepared_insert_secondary = $connection->prepare("INSERT INTO tbl_SecondaryFocus(SecondaryFocus, Organization) VALUES (?, ?) ON DUPLICATE KEY UPDATE SecondaryFocus = ?");
	$prepared_insert_performs  = $connection->prepare("INSERT INTO tbl_Performs(PrimaryFocus, SecondaryFocus, Organization) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE PrimaryFocus = ?, SecondaryFocus = ?");
	$prepared_insert_primary  ->bind_param("sss",  $PrimaryFocus, $SID, $PrimaryFocus);
	$prepared_insert_secondary->bind_param("sss",  $SecondaryFocus, $SID, $SecondaryFocus);
	$prepared_insert_performs ->bind_param("sssss", $PrimaryFocus, $SecondaryFocus, $SID, $PrimaryFocus, $SecondaryFocus);
	
	$prepared_insert_archetype  = $connection->prepare("INSERT INTO tbl_OrgArchetypes(Organization, Archetype) VALUES (?, ?) ON DUPLICATE KEY UPDATE Archetype = ?");
	$prepared_insert_filterarch = $connection->prepare("INSERT INTO tbl_FilterArchetypes(Archetype, Organization) VALUES (?, ?) ON DUPLICATE KEY UPDATE Archetype = ?");
	$prepared_insert_archetype  ->bind_param("sss", $SID, $Archetype, $Archetype);
	$prepared_insert_filterarch ->bind_param("sss", $Archetype, $SID, $Archetype);
	
	$prepared_insert_roleplay = $connection->prepare("INSERT INTO tbl_RolePlayOrgs(Organization) VALUES (?) ON DUPLICATE KEY UPDATE Organization = ?");
	$prepared_delete_roleplay = $connection->prepare("DELETE from tbl_RolePlayOrgs WHERE Organization = ?");
	$prepared_insert_roleplay->bind_param("ss", $SID, $SID);
	$prepared_delete_roleplay->bind_param("s", $SID);
	
	$prepared_insert_language = $connection->prepare("INSERT INTO tbl_OrgFluencies(Organization, Language) VALUES (?, ?) ON DUPLICATE KEY UPDATE Language = ?");
	$prepared_insert_filter = $connection->prepare("INSERT INTO tbl_FilterFluencies(Language, Organization) VALUES (?, ?) ON DUPLICATE KEY UPDATE Language = ?");
	$prepared_insert_language->bind_param("sss", $SID, $Language, $Language);
	$prepared_insert_filter->bind_param("sss", $Language, $SID, $Language);
	
	$numberInserted = 0;
	for($x = 1;; $x++){//$x is current page number in query string
		//3) Query SC-API (all orgs)
		for($failCounter = 0;;++$failCounter){
			$lines = file_get_contents(
				"http://sc-api.com/?api_source=live&system=organizations&action=all_organizations&source=rsi&start_page=$x&end_page=$x&items_per_page=1&sort_method=&sort_direction=ascending&expedite=0&format=raw"
			);
			if($lines)break;
			sleep(1);//try a few more times if request fails
			if($failCounter > 3)break 2;
		}
		$dataArray = json_decode($lines, true);//json to php associated array
		if($dataArray == false)exit("failed to decode\n");
		unset($lines);
		
		//if we have read all orgs
		if($dataArray["data"] == null){
			echo "Finished inserting $numberInserted Orgs\n";
			break;
		}
		
		echo "Fetched metadata on " . sizeof($dataArray["data"]) . " Orgs\n";
		
		//4) Sub-query Org (more data)
		foreach ($dataArray["data"] as $org){
			//only query the org if it's new or has changed its size
			if(  didSizeChange($org['sid'], $org['member_count'], $connection)  ){
				for($failCounterSingleOrg = 0;;++$failCounterSingleOrg){//loop in case request fails due to poor connection
					$subquery = file_get_contents(
						//note sc-api does not provide language information on live results
						'http://sc-api.com/?api_source=live&system=organizations&action=single_organization&target_id='
						. $org['sid'] . '&expedite=0&format=raw'
					);
					if($subquery)break;
					sleep(1);
					if($failCounterSingleOrg > 2){
						echo "FAILED to query API live for org with SID == " . $org['sid'] . "\n";
						continue(2);
					}
				}
				$orgArray = json_decode($subquery, true);
				unset($subquery);
				if($orgArray['data'] == null)echo "WARNING: Org null (in API live result!)\n";
			
				//5) Bind data to statement
				$SID            = strtoupper( $orgArray['data']['sid'] );
				$Name           = rawurldecode(  $orgArray['data']['title']  );
				$NameUpper      = strtoupper($Name);
				$Icon           = $orgArray['data']['logo'];
				$Size           = intval( $orgArray['data']['member_count'] );
				$Recruiting     = $orgArray['data']['recruiting'];
				$Archetype      = $orgArray['data']['archetype'];
				$Commitment     = $orgArray['data']['commitment'];
				$Roleplay       = $orgArray['data']['roleplay'];
				$PrimaryFocus   = $orgArray['data']['primary_focus'];
				$SecondaryFocus = $orgArray['data']['secondary_focus'];
				$Language       = $orgArray['data']['lang'];
				//banner
				//headline
				//history
				//manifesto
				//charter
				unset($orgArray);

				//test code
				//echo "SID: " . $SID . "\n";
				//echo "Name: " . $Name . "\n";
				//echo "$Icon \n";
				//echo "Members: " . $Size . "\n";
				//echo "Commitment: " . $Commitment . "\n";
				//echo "Primary: " . $PrimaryFocus . "\n";
				//echo "\n";

				//6) Execute Database Queries		
				$connection->query('SET foreign_key_checks = 0');//speed up inserting into hub table);
				if(!$prepared_insert_org->execute())echo "Error inserting Org $SID $Name\n";
				$connection->query('SET foreign_key_checks = 1');
			
				if(!$prepared_insert_name->execute())echo "Error inserting Name $SID $Name\n";
				if(!$prepared_insert_commits->execute())echo "Error inserting Commits $SID $Commitment\n";
				if( $Recruiting === "No" ){
						if(!$prepared_insert_full->execute())echo "Error inserting recruiting $SID $Recruiting\n";
				}
				else if(!$prepared_delete_full->execute())echo "Error inserting recruiting $SID $Recruiting\n";
				if(!$prepared_insert_primary->execute())echo "Error inserting primary $SID $PrimaryFocus\n";
				if(!$prepared_insert_secondary->execute())echo "Error inserting secondary $SID $SecondaryFocus\n";
				if(!$prepared_insert_performs->execute())echo "Error inserting performs $SID\n";
				if(!$prepared_insert_archetype->execute())echo "Error inserting archetype $SID $Archetype\n";
				if(!$prepared_insert_filterarch->execute())echo "Error inserting filter archetype $SID $Archetype\n";
				if( $Roleplay === "Yes" ){
						if(!$prepared_insert_roleplay->execute())echo "Error inserting roleplay $SID $Roleplay\n";
				}
				else if(!$prepared_delete_roleplay->execute())echo "Error inserting roleplay $SID $Roleplay\n";
				if($Language != null){
					if(!$prepared_insert_language->execute())echo "Error inserting language $SID $Language\n";
					if(!$prepared_insert_filterlang->execute())echo "Error inserting filter language $SID $Language\n";
				}
				++$numberInserted;
				echo "inserted SID = $SID\n";
			}
		}
		$connection->commit();
		if($x % 32 == 1)echo "Inserted $numberInserted Orgs\n";
	}
	
	//7) Close Connection
	$connection->autocommit(TRUE);
	$prepared_insert_org->close();
	$prepared_insert_name->close();
	$prepared_insert_commits->close();
	$prepared_insert_full->close();
	$prepared_delete_full->close();
	$prepared_insert_primary->close();
	$prepared_insert_secondary->close();
	$prepared_insert_performs->close();
	$prepared_insert_archetype->close();
	$prepared_insert_filterarch->close();
	$prepared_insert_roleplay->close();
	$prepared_delete_roleplay->close();
	$prepared_insert_language->close();
	$prepared_insert_filter->close();
	
	echo "Done inserts! Rebuilding table clustering...\n";
	
	//8) Recluster Tables
	$connection->query('ALTER TABLE tbl_Organizations ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_OrgNames ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_Commits ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_RolePlayOrgs ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_OrgArchetypes ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_FilterArchetypes ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_FullOrgs ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_ExclusiveOrgs ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_PrimaryFocus ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_SecondaryFocus ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_FilterArchetypes ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_OrgFluencies ENGINE=INNODB');
	$connection->query('ALTER TABLE tbl_FilterFluencies ENGINE=INNODB');
	
	//9) Close Connection
	$connection->close();
	echo "All insertions complete (total: $numberInserted)\n";
?>
