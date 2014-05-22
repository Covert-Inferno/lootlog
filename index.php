<?php

//connect to the database
$db_connectionString = 'mysql:host=localhost;dbname=PutYourDatabaseNameHere';
$db_username = 'PutYourUsernameHere';
$db_password = 'PutYourPasswordHere';
$eve_character_name = 'PutACharacterNameWhereYouCanBeContactedHere';

function timeThis($s) {
        static $prevTime;
        static $timings;

        if (empty($prevTime)) {
                $prevTime = ['start', microtime(true)];
                $timings = [];
        } // if

        $timings[] = [$prevTime[0], $prevTime[1]];
        $prevTime = [$s, microtime(true)];

        if ($s == 'end') {
                $timings[] = ['end', microtime(true)];
                $prev = $timings[0][1];

                foreach($timings as $t) {
                        echo $t[0] . ' => ' . ($t[1] - $prev) . PHP_EOL;
                } // foreach
        } // if
} // timeThis()

//initialize a simple loot class
class Loot {
	public $itemid;
	public $itemname;
	public $itemcount;
	public $itemprice;
}

$lootstack = array();

//check if a lootlog is being posted
if(isset($_POST['lootlog'])){
	$db_conn = new PDO($db_connectionString, $db_username, $db_password);

	/* 
	 * replace 3-9 spaces with tabs in case the lootlog was copied from a mail 
	 * (evemail converts tabs to 4 spaces for example. Wont work for EVE Gate 
	 * sadly since that converts to a single space. If someone has suggestions
	 * to fix this without losing too much performance, please let me know)
	 */
	timeThis('split lootlog - step 1');
	$lootLog = preg_replace('#\s{3,9}#', "\t", $_POST['lootlog']);
	timeThis('split lootlog - done');

	//split the lootlog by lines
	$itemNameList = '';
	foreach(preg_split("/((\r?\n)|(\r\n?))/", $lootLog) as $lootline){
		//split the lootlog by tabs
		$lootarray = preg_split('/\t+/', $lootline);
		
		//put data in a new loot item
		$loot = new Loot();
		$loot->itemname = $lootarray[0];
		$loot->itemcount = (is_numeric($lootarray[1]) ? $lootarray[1] : 1);
		
		//add item to stack
		if (isset($lootstack[strtolower($loot->itemname)])) {
			$lootstack[strtolower($loot->itemname)]->itemcount += $loot->itemcount;
		} else {
			$lootstack[strtolower($loot->itemname)] = $loot;
		} // else

		$itemNameList .= empty($itemNameList) ? $db_conn->quote($loot->itemname) : ',' . $db_conn->quote($loot->itemname);
	}
	timeThis('foreach over loot log list done');

	if (!empty($itemNameList)) {
		/* delete items from the cache which are expired. we have a small race in here, but that won't matter */
		$db_conn->exec('DELETE FROM eve_inv_pricecache WHERE price_valid_till < NOW()');
		timeThis('emptying out database done');
		
		/* and retrieve items from the cache */
		$itemdetails = $db_conn->query('SELECT eit.name, eit.type_id, eip.cached_price 
						   FROM eve_inv_types eit
		                                   LEFT JOIN eve_inv_pricecache eip ON (eit.type_id = eip.type_id) 
		                                     WHERE name IN (' . $itemNameList . ') ');
		foreach($itemdetails as $itemrow){
			$lootstack[strtolower($itemrow['name'])]->itemid = $itemrow['type_id'];
			$lootstack[strtolower($itemrow['name'])]->itemprice = $itemrow['cached_price'];
		} // foreach
		timeThis('query for all items done');
	} // if

	//make a simple typeid string for the json request
	$itemids = '';
	foreach($lootstack as $loot){
		if ($loot->itemprice === null) {
			$itemids.= empty($itemids) ? $loot->itemid : ',' . $loot->itemid;
		}
	}
	timeThis('created list of itemids to query');

	//do a json request @ the EMDR source
	if (!empty($itemids)) {
		$emdr = file_get_contents('http://api.eve-marketdata.com/api/item_prices2.json?char_name=' . $eve_character_name . '&type_ids=' . $itemids . '&region_ids=10000002&buysell=s');
		$pricelist = json_decode($emdr);
		if(!is_object($pricelist)){
			die("<b>error:</b> something went wrong using the eve-marketdata.com api.<br />\nThis is the server answer:<br />\n" . $emdr);
		}
		//set the prices in the stack
		foreach($pricelist->emd->result as $priceresult){
			foreach($lootstack as $loot){
				if($loot->itemid == $priceresult->row->typeID){
					$loot->itemprice = $priceresult->row->price;
		
					$db_conn->exec('INSERT INTO eve_inv_pricecache(type_id, cached_price, valid_till) 
					                  VALUES(' . (int) $loot->itemid . ', 
					                         ' . (float) $loot->itemprice . ',
					                         NOW() + INTERVAL 1 DAY)');
				}
			}
		}
	}
	timeThis('queried API');

	//get a total price
	$totalprice = 0.0;
	foreach($lootstack as $loot){
		$totalprice += $loot->itemprice * $loot->itemcount;
	}
	timeThis('calculated total price');
}
?>
<html>
<head>
	<title> EVE Lootlog Toolbox </title>
	<style>
	*{
		font-family: Verdana, Arial;
		font-size: 12px;
	}
	.number{
		text-align: right;
	}
	td{
		padding: 3px;
	}
	</style>
</head>
<body>
<?php
if(isset($_POST['lootlog'])){
	echo("<table>\n<tr><th>Item</th><th>Count</th><th>Price/unit</th><th>Price/total</th></tr>\n");
	foreach($lootstack as $loot){
		echo("<tr><td>".$loot->itemname."</td><td class='number'>".$loot->itemcount."</td><td class='number'>".number_format($loot->itemprice,2)."</td><td class='number'>".number_format($loot->itemcount * $loot->itemprice,2)."</td></tr>\n");
	}
	echo("<tr><td class='number' colspan=3>Total:</td><td class='number' style='font-weight: bold;'>".number_format($totalprice, 2)."</td></tr>");
	$corppart = ($totalprice / 100) * $_POST['corppercentage'];
	echo($corppart > 0 ? "<tr><td class='number' colspan=3>Corp takes " . $_POST['corppercentage'] . "%:</td><td class='number' style='font-weight: bold;'>".number_format($corppart, 2)."</td></tr>" : '');
	$memberpart = ($totalprice - $corppart) / $_POST['shares'];
	echo($memberpart > 0 ? "<tr><td class='number' colspan=3>Member share:</td><td class='number' style='font-weight: bold;'>".number_format($memberpart, 2)."</td></tr>" : '');
	echo("</table>\n");
}
?>
<form method="post" action="index.php">
	<textarea name="lootlog" rows=20 cols=120><?php echo(isset($_POST['lootlog']) ? $_POST['lootlog'] : ''); ?></textarea><br />
	<span>Number of shares:</span>
	<input type="nummeric" name="shares" value="<?php echo(isset($_POST['shares']) ? $_POST['shares'] : '5'); ?>" /><br />
	<span>% for corp:</span>
	<input type="nummeric" name="corppercentage" value="<?php echo(isset($_POST['corppercentage']) ? $_POST['corppercentage'] : '20'); ?>" /><br />
	<input type="submit" value="Magic!" />
</form>
</body>
</html>
<?php
	timeThis('end');
