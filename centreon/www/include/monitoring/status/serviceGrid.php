<?
/**
Oreon is developped with GPL Licence 2.0 :
http://www.gnu.org/licenses/gpl.txt
Developped by : Julien Mathis - Romain Le Merlus

The Software is provided to you AS IS and WITH ALL FAULTS.
OREON makes no representation and gives no warranty whatsoever,
whether express or implied, and without limitation, with regard to the quality,
safety, contents, performance, merchantability, non-infringement or suitability for
any particular or intended purpose of the Software found on the OREON web site.
In no event will OREON be liable for any direct, indirect, punitive, special,
incidental or consequential damages however they may arise and even if OREON has
been previously advised of the possibility of such damages.

For information : contact@oreon.org
*/

	if (!isset($oreon))
		exit();

	$hg = array();
	$h_data = array();
	$svc_data = array();

	$ret =& $pearDB->query("SELECT * FROM hostgroup WHERE hg_activate = '1' ORDER BY hg_name");
	if (PEAR::isError($res))
		print "Mysql Error : ".$res->getMessage();

	$TabLca = getLcaHostByName($pearDB);

	while ($r =& $ret->fetchRow()){
		$ret_h =& $pearDB->query(	"SELECT host_host_id, host_name, host_alias FROM hostgroup_relation,host,hostgroup ".
									"WHERE hostgroup_hg_id = '".$r["hg_id"]."' AND hostgroup.hg_id = hostgroup_relation.hostgroup_hg_id ".
									"AND hostgroup_relation.host_host_id = host.host_id AND host.host_register = '1' AND hostgroup.hg_activate = '1'");
		if (PEAR::isError($ret_h)) 
			print "Mysql Error : ".$ret_h->getMessage();
		$cpt = 0;
		while ($r_h =& $ret_h->fetchRow()){
			if ($oreon->user->admin || !hadUserLca($pearDB) || (hadUserLca($pearDB) && isset($TabLca["LcaHostGroup"][$r["hg_name"]]))){
				$service_data_str = NULL;
				$host_data_str = "<a href='./oreon.php?p=201&o=hd&host_name=".$r_h["host_name"]."'>" . $r_h["host_name"] . "</a> (" . $r_h["host_alias"] . ")";
				$cpt_host = 0;
				if (isset($tab_host_service[$r_h["host_name"]]))
					foreach ($tab_host_service[$r_h["host_name"]] as $key => $value){
						$service_data_str .= 	"<span style='background:".
												$oreon->optGen["color_".strtolower($service_status[$r_h["host_name"]."_".$key]["current_state"])]."'>".
												"<a href='./oreon.php?p=202&o=svcd&host_name=".$r_h["host_name"]."&service_description=".$key."'>".$key.
												"</a></span> &nbsp;&nbsp;";
						$cpt_host++;
					}
				if ($cpt_host){
					$hg[$r["hg_name"]] = array("name" => $r["hg_name"], 'alias' => $r["hg_alias"], "host" => array());
					$hg[$r["hg_name"]]["host"][$cpt] = $r_h["host_name"];
				} 
				$h_data[$r["hg_name"]][$r_h["host_name"]] = $host_data_str;
				$svc_data[$r["hg_name"]][$r_h["host_name"]] = $service_data_str;
				$cpt++;
			}
		}
	}

	# Smarty template Init
	$tpl = new Smarty();
	$tpl = initSmartyTpl($path, $tpl, "/templates/");
	$tpl->assign("refresh", $oreon->optGen["oreon_refresh"]);
	$tpl->assign("p", $p);
	$tpl->assign("hostgroup", $hg);
	$tpl->assign("h_data", $h_data);
	$tpl->assign("lang", $lang);
	$tpl->assign("svc_data", $svc_data);
	$tpl->display("serviceGrid.ihtml");

	$tpl = new Smarty();
	$tpl = initSmartyTpl("./", $tpl);
	$tpl->assign('lang', $lang);
	$tpl->display("include/common/legend.ihtml");
?>