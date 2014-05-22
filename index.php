<?php

//connect to the database
$db_connectionString = 'mysql:host=localhost;dbname=PutYourDatabaseNameHere';
$db_username = 'PutYourUsernameHere';
$db_password = 'PutYourPasswordHere';
$eve_character_name = 'PutACharacterNameWhereYouCanBeContactedHere';

$db_conn = new PDO($db_connectionString, $db_username, $db_password);

//initialize a simple loot class
class loot{
	public $itemid;
	public $itemname;
	public $itemcount;
	public $itemprice;
}

$lootstack = array();

//check if a lootlog is being posted
if(isset($_POST['lootlog'])){

	/* 
	 * replace 3-9 spaces with tabs in case the lootlog was copied from a mail 
	 * (evemail converts tabs to 4 spaces for example. Wont work for EVE Gate 
	 * sadly since that converts to a single space. If someone has suggestions
	 * to fix this without losing too much performance, please let me know)
	 */
	 
	$_POST['lootlog'] = preg_replace('#\s{3,9}#', "\t", $_POST['lootlog']);
	
	//split the lootlog by lines
	foreach(preg_split("/((\r?\n)|(\r\n?))/", $_POST['lootlog']) as $lootline){
		//split the lootlog by tabs
		$lootarray = preg_split('/\t+/', $lootline);
		
		//put data in a new loot item
		$loot = new loot();
		$loot->itemname = $lootarray[0];
		$loot->itemcount = (is_numeric($lootarray[1]) ? $lootarray[1] : 1);
		
		//add item to stack
		$lootstack[$loot->itemname] = $loot;
	}

	/*
	 * Retrieve all items in one go
	 */
	$itemNameList = '';
	foreach($lootstack as $lootItem) {
		$itemNameList .= empty($itemNameList) ? $db_conn->quote($lootItem->itemname) : ',' . $db_conn->quote($lootItem->itemname);
	} // foreach
	
	//get itemid from the database (EMDR dump)
	$itemdetails = $db_conn->query('SELECT * FROM eve_inv_types WHERE name IN (' . $itemNameList . ') ');
	foreach($itemdetails as $itemrow){
		$lootstack[$itemrow['name']]->itemid = $itemrow['type_id'];
	} // foreach

	//make a simple typeid string for the json request
	$first = true;
	foreach($lootstack as $loot){
		$itemids.= $first ? $loot->itemid : ',' . $loot->itemid;
		$first = false;
	}

	//do a json request @ the EMDR source
	$pricelist = json_decode(file_get_contents('http://api.eve-marketdata.com/api/item_prices2.json?char_name=' . $eve_character_name . '&type_ids=' . $itemids . '&region_ids=10000002&buysell=s'));

	//set the prices in the stack
	foreach($pricelist->emd->result as $priceresult){
		foreach($lootstack as $loot){
			if($loot->itemid == $priceresult->row->typeID){
				$loot->itemprice = $priceresult->row->price;
			}
		}
	}
	
	//get a total price
	$totalprice = 0.0;
	foreach($lootstack as $loot){
		$totalprice += $loot->itemprice * $loot->itemcount;
	}
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
