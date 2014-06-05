<?php

Class MetsExporter 
{ 
  /**
   *Determines whether to send headers telling the browser to 
   *download the file automatically
   *(I think this is obsolete, but have to make sure it is not
   *referenced anywhere before deleting it)
   */
  public static $force_download = true;

  /**
   *An array containing the names of metadata elements
   *which will be included in the Admin Metadata section,
   *rather than the Descriptive Metadata section
   */
  private $_admElNames;

  /**
   *A structured array of element sets and elements for 
   *the Admin Metadata section of the mets file
   */
  private $_admElements = array();
  
  //private $_includeDeriv = get_option('mets_includeDeriv');

  /**
   *Determines whether to include item curation logs as 
   *administrative metadata
   */
  private $_includeLogs;

  /**
   *Set up some default values for instance variables when 
   *class is instantiated
   *
   *@return void
   */
  function __construct()
  {
    $this->_admElNames  = unserialize(get_option('mets_admElements'));
    $this->_includeLogs = get_option('mets_includeLogs');
  }

  /**
   *Returns METS xml for a given single Omeka item
   *
   *@param int $itemID The ID of the Omeka item
   *@return string $xml The contents of the METS file
   */
  public function exportItem($itemID)
  {
    ob_start();
    $this->_generateMETS($itemID,false);
    return ob_get_clean();
  }

  /**
   *Export an entire collection as a zip file filled with METS xml 
   *files for each item.
   *
   *@param int $collectionID The ID of the omeka collection to export
   *@return void
   */
  public function exportCollection($collectionID)
  {
    include_once(dirname(dirname(__FILE__)).'/libraries/zipstream-php-0.2.2/zipstream.php');

    $collection = get_record_by_id("collection",$collectionID);

    $items = get_records('Item',array('collection'=>$collectionID),999);
    
    error_reporting(0);

    $zip = new ZipStream('Collection_'.$collection->id.'.zip');

    foreach($items as $item)
      {
	ob_start();
	$this->_generateMETS($item->id,false);
	$zip->add_file("Item_".$item->id.".mets.xml", ob_get_clean() );
      }
    $zip->finish();
  }


  /**
   *Generate and print xml output for a given Omeka item
   *
   *@param int $itemID The ID of the Omeka item
   *@param bool $force_download If true, headers are sent
   *telling the browser to download the file. Default: true.
   *return void
   */
  private function _generateMETS($itemID,$force_download=true)
  {
    if(!is_numeric($itemID))
      {
	echo "ERROR: Invalid item ID";
	die();
      }

    $item = get_record_by_id("item",$itemID);
    $owner = $item->getOwner();
    $currentuser = current_user();

    if(is_null($item)||empty($item))
      {
	echo "ERROR: Invalid item ID";
	die();
      }

    //$title = "testTitle";
    $titles = $item->getElementTexts('Dublin Core','Title');
    $title = $titles[0];
    $title = htmlspecialchars($title);
    $type = $item->getItemType();
    $files = $item->getFiles();
    $agents = $this->_getAgents($item);


    if($force_download)
      {
	
      }
    echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";

    //--------------------
    //BEGIN OUTER METS DIV
    //--------------------

    echo '<METS:mets ';
    echo 'xmlns:METS="http://www.loc.gov/METS/" ';
    echo 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ';
    echo 'xmlns:dc="http://purl.org/dc/elements/1.1/" ';
    echo 'xmlns:xlink="http://www.w3.org/1999/xlink" ';
    echo 'xsi:schemaLocation="http://www.loc.gov/METS/ http://www.loc.gov/standards/mets/mets.xsd  http://www.loc.gov/mods/v3 http://www.loc.gov/standards/mods/v3/mods-3-0.xsd" ';
    echo 'ID="ITEM_'.$itemID.'" ';
    echo 'OBJID="ITEM_'.$itemID.'" ';
    echo 'LABEL="'.$title.'" ';
    echo 'TYPE="'.$type->name.'" ';

    //echo 'PROFILE="OMEKA PROFILE ONCE IT IS REGISTERED" ';

    echo ">\n";

    //--------------------
    //METS HEADER
    //--------------------

    echo "\n<METS:metsHdr ";
    echo 'CREATEDATE="'.date("Y-m-d\TH:i:s").'" ';
    //echo 'LASTMODDATE="'..'" ';
    echo 'ID="HDR_ITEM'.$itemID.'" ';
    echo 'ADMID="AMD_ITEM'.$itemID.'" ';
    echo ">\n";

    foreach($agents as $agent)
      {
	echo '<METS:agent ';
	echo 'ROLE="'.$agent[1].'" ';
	echo 'TYPE="'.$agent[2].'" ';
	echo ">\n";
    
	echo "<METS:name>";
	echo $agent[0];
	echo "</METS:name>\n";

	echo "<METS:note>";
	echo $agent[3];
	echo "</METS:note>\n";

	echo "</METS:agent>\n";
      }

    echo "</METS:metsHdr>\n";

    //--------------------
    //DESCRIPTIVE METADATA
    //--------------------

    //---ITEM dmdSec

    echo "\n<METS:dmdSec ";
    echo 'ID="DMD_ITEM'.$itemID.'" ';
    echo ">\n";
    
    $elementArray = $item->getAllElements();

    foreach($elementArray as $elementSetName => $elements)
      {
	ob_start();
	$flag = false;

	$eSSlug=$this->_getElementSetSlug($elementSetName);
    
	echo '<METS:mdWrap ';
	echo 'ID="MDW_ITEM'.$itemID.'_'.$eSSlug.'" ';
	echo 'LABEL="'.$elementSetName.'" ';
	if($this->_is_type_other($eSSlug))
	  {
	    echo 'MDTYPE="OTHER" ';
	    $eSSlug = "";
	    echo 'OTHERMDTYPE="'.strtoupper(preg_replace('/\s+/', '', $elementSetName)).'" ';
	  }
	echo ">\n";

	echo "<METS:xmlData>\n";
    
	if($eSSlug!=="")
	  $eSSlug .= ":";

	foreach($elements as $element)
	  {
	    if(array_key_exists($element->name,$this->_admElNames))
	      {
		if(!array_key_exists($elementSetName,$this->_admElements))
		  $this->_admElements[$elementSetName] = array();
		$this->_admElements[$elementSetName][]=$element;
		continue;
	      }

	    $elementTexts =  $item->getElementTexts($elementSetName,$element->name);
	    if(empty($elementTexts))
	      continue;
	    $flag = true;

	    foreach($elementTexts as $elementText)
	      {
		echo '<'.$eSSlug.preg_replace('/\s+/', '',$element->name).">";
		echo htmlspecialchars($elementText->text);
		echo "</".$eSSlug.preg_replace('/\s+/', '', $element->name).">\n";
	      }
	  }

	echo "</METS:xmlData>\n";
	echo "</METS:mdWrap>\n";
	if($flag)
	  ob_end_flush();
	else
	  ob_end_clean();
      }

    echo "</METS:dmdSec>\n";


    //----FILE DmdSecs---//

    $i=0;
    foreach($files as $file)
      {
	echo "\n<METS:dmdSec ";
	echo 'ID="DMD_FILE'.$file->item_id.$i.'" ';
	echo ">\n";

	$elements = $file->getAllElements();

	foreach($elements as $elementSetName => $elements)
	  {
	    ob_start();
	    $flag=false;

	    $eSSlug=$this->_getElementSetSlug($elementSetName);

	    echo '<METS:mdWrap ';
	    echo 'ID="MDW_FILE'.$file->item_id.$i.'" ';
	    //echo 'MIMETYPE="'..'" ';
	    echo 'LABEL="'.$elementSetName.'" ';
	    if($this->_is_type_other($eSSlug))
	      {
		echo 'MDTYPE="OTHER" ';
		if($eSSlug==="unknown")
		  $eSSlug = "";
		echo 'OTHERMDTYPE="'.strtoupper(preg_replace('/\s+/', '', $elementSetName)).'" ';
	      }
	    echo ">\n";

	    echo "<METS:xmlData>\n";
	
	    if($eSSlug!=="")
	      $eSSlug .= ":";

	    foreach($elements as $element)
	      {
		if(array_key_exists($element->name,$this->_admElNames))
		  continue;

		$elementTexts =  $file->getElementTexts($elementSetName,$element->name);

		if(empty($elementTexts))
		  continue;
		$flag = true;

		foreach($elementTexts as $elementText)
		  {
		    echo '<'.$eSSlug.preg_replace('/\s+/', '',$element->name).">";
		    echo htmlspecialchars($elementText->text);
		    echo "</".$eSSlug.preg_replace('/\s+/', '', $element->name).">\n";
		  }
	      }

	    echo "</METS:xmlData>\n";
	    echo "</METS:mdWrap>\n";
	    if($flag)
	      ob_end_flush();
	    else
	      ob_end_clean();
	  }    
	echo "</METS:dmdSec>\n";
      }
 
    //----COLLECTION dmdsec----//



    //--------------------
    //ADMINISTRATIVE METADATA
    //--------------------

    //----Item ADMSEC-----

    echo "\n<METS:amdSec ";
    echo 'ID="AMD_ITEM'.$itemID.'" ';
    echo ">\n";

    $rightsMD = "";
    $sourceMD = "";
    $techMD = "";
    $digiprovMD = "";
    $md_start = "";
    $md_end = "";
    $mdWrap = array();

    foreach($this->_admElements as $elementSetName=>$elements)
      {

	$eSSlug=$this->_getElementSetSlug($elementSetName);
    

	ob_start();
	echo '<METS:mdWrap ';
	echo 'ID="MDW_ITEM'.$itemID.'_'.$eSSlug.'" ';
	echo 'LABEL="'.$elementSetName.'" ';
	if($this->_is_type_other($eSSlug))
	  {
	    echo 'MDTYPE="OTHER" ';
	    $eSSlug = "";
	    echo 'OTHERMDTYPE="'.strtoupper(preg_replace('/\s+/', '', $elementSetName)).'" ';
	  }

	echo ">\n<METS:xmlData>\n";

	$MDwrap['begin'] = ob_get_clean();

	if($eSSlug!=="")
	  $eSSlug .= ":";

	foreach($elements as $element)
	  {
	    $MDtype = $this->_admElNames[$element->name];

	    $elementTexts =  $item->getElementTexts($elementSetName,$element->name);
	    if(empty($elementTexts))
	      continue;
	    $flag = true;

	    ob_start();
	    foreach($elementTexts as $elementText)
	      {
		echo '<'.$eSSlug.preg_replace('/\s+/', '',$element->name).">";
		echo htmlspecialchars($elementText->text);
		echo "</".$eSSlug.preg_replace('/\s+/', '', $element->name).">\n";
	      }
	    $MDwrap[$MDtype] = ob_get_clean();
	  }

	ob_start();
	echo "</METS:xmlData>\n";
	echo "</METS:mdWrap>\n";
	$MDwrap['end']=ob_get_clean();

	if(!empty($MDwrap['rights']))
	  echo($MDwrap['begin']."<mets:techMD>".$MDwrap['rights']."</mets:techMD>".$MDwrap['end']);
	if(!empty($MDwrap['source']))
	  echo($MDwrap['begin']."<mets:sourceMD>".$MDwrap['source']."</mets:sourceMD>".$MDwrap['end']);
	if(!empty($MDwrap['tech']))
	  echo($MDwrap['begin']."<mets:techMD>".$MDwrap['tech']."</mets:techMD>".$MDwrap['end']);
	if(!empty($MDwrap['digiprov']))
	  echo($MDwrap['begin']."\n<mets:digiprovMD>\n".$MDwrap['digiprov']."\n</mets:digiprovMD>\n".$MDwrap['end']);

      }

    if($this->_includeLogs && plugin_is_active('HistoryLog')) {
	$logItems = HistoryLog_Helper_Log::GetItemLog($itemID,999);
	
	echo '<METS:mdWrap ';
	echo 'ID="MDW_ITEM'.$itemID.'_CURATIONLOG" ';
	echo 'LABEL="Curation Log" ';
	echo 'MDTYPE="OTHER" ';
	echo 'OTHERMDTYPE="CURATIONLOG" ';

	echo ">\n<METS:xmlData>\n";

	foreach($logItems as $logItem) {
	  echo '<logEvent>';
	  echo 'Item '.$logItem['type'].' \nby user '.$logItem['user'].' \nat '.$logItem['time'];
	  if(isset($logItem['value']))
	    echo '\n '.$logItem['value'];
	  echo '</logEvent>';
	}

	echo "</METS:mdwrap>";
	echo "</METS:xmldata>";
    }

    echo "</METS:amdSec>\n";

    //----FILE AmdSecs---//

    $fileAdmIds = array();
    $i=0;
    foreach($files as $file)
      {
	$flag=false;
        ob_start();
	echo "\n<METS:amdSec ";
	echo 'ID="AMD_FILE'.$file->item_id.$i.'" ';
	echo ">\n";

	$rightsMD = "";
	$sourceMD = "";
	$techMD = "";
	$digiprovMD = "";
	$md_start = "";
	$md_end = "";
	$mdWrap = array();

	foreach($this->admElements as $elementSetName => $elements)
	  {
	    $eSSlug=$this->_getElementSetSlug($elementSetName);

	    ob_start();
	    echo '<METS:mdWrap ';
	    echo 'ID="MDW_FILE'.$file->item_id.$i.'" ';
	    echo 'LABEL="'.$elementSetName.'" ';
	    if($this->_is_type_other($eSSlug))
	      {
		echo 'MDTYPE="OTHER" ';
		if($eSSlug==="unknown")
		  $eSSlug = "";
		echo 'OTHERMDTYPE="'.strtoupper(preg_replace('/\s+/', '', $elementSetName)).'" ';
	      }
	    echo ">\n<METS:xmlData>\n";
	    $MDwrap['begin']=ob_get_clean();
	
	    if($eSSlug!=="")
	      $eSSlug .= ":";

	    foreach($elements as $element)
	      {
		$MDtype = $this->_admElNames[$element->name];

		$elementTexts =  $file->getElementTexts($elementSetName,$element->name);

		if(empty($elementTexts))
		  continue;

		$flag = true;
		
		ob_start();
		foreach($elementTexts as $elementText)
		  {
		    echo '<'.$eSSlug.preg_replace('/\s+/', '',$element->name).">";
		    echo htmlspecialchars($elementText->text);
		    echo "</".$eSSlug.preg_replace('/\s+/', '', $element->name).">\n";
		  }
		$MDwrap[$MDtype] = ob_get_clean();
	      }
	    
	    ob_start();
	    echo "</METS:xmlData>\n";
	    echo "</METS:mdWrap>\n";
	    $MDwrap['end']=ob_get_clean();

	    if(!empty($MDwrap['rights']))
	      echo($MDwrap['begin']."<mets:techMD>".$MDwrap['rights']."</mets:techMD>".$MDwrap['end']);
	    if(!empty($MDwrap['source']))
	      echo($MDwrap['begin']."<mets:sourceMD>".$MDwrap['source']."</mets:sourceMD>".$MDwrap['end']);
	    if(!empty($MDwrap['tech']))
	      echo($MDwrap['begin']."<mets:techMD>".$MDwrap['tech']."</mets:techMD>".$MDwrap['end']);
	    if(!empty($MDwrap['digiprov']))
	      echo($MDwrap['begin']."\n<mets:digiprovMD>\n".$MDwrap['digiprov']."\n</mets:digiprovMD>\n".$MDwrap['end']);

	    echo "</METS:xmlData>\n";
	    echo "</METS:mdWrap>\n";

	  }    
	echo "</METS:amdSec>\n";

	if($flag) {
	  $fileAdmIds[$file->item_id] = 'AMD_FILE'.$file->item_id.$i;
	  ob_end_flush();
	}else{
	  ob_end_clean();
	}
      }

    //--------------------
    //FILES
    //--------------------

    echo "\n<METS:fileSec ";
    echo 'ID="FILES_ITEM'.$itemID.'" ';
    if(isset($fileAdmIds[$file->item_id]))
      echo 'AMDID="'.$fileAdmIds[$file->item_id].'" ';
    echo ">\n";

    $i=0;
    foreach($files as $file)
      {
	echo '<METS:file ';
	echo 'ID="FILE'.$file->item_id.$i.'" ';
	echo 'MIMETYPE="'.$file->mime_type.'" ';
	echo 'SIZE="'.$file->size.'" ';
	echo 'CREATED="'.$file->added.'" ';
	echo 'DMDID="DMD_FILE'.$file->item_id.$i.'" ';
	echo ">\n";

	echo '<FLocat ';
	echo 'LOCTYPE="URL" ';
	echo 'xlink:href="'.$file->getWebPath().'" ';
	echo "></FLocat>\n";
     

	echo "</METS:file>\n";
    
	$i++;
      }
    //**TODO** separate fileGroups for derivative images?
    //echo '</METS:fileGrp>\n';

    echo "</METS:fileSec>\n";

    //--------------------
    //STRUCTURAL MAP SECTION
    //--------------------

    echo "\n<METS:structMap ";
    //echo 'ID="STR_ITEM'.$itemID.'" ';
    //echo 'TYPE="'..'" ';
    //echo 'LABEL="'..'" ';
    echo ">\n";

    echo '<METS:div ';
    echo 'TYPE="ITEM" ';
    echo 'DMDID="DMD_ITEM'.$itemID.'" ';
    echo 'AMDID="AMD_ITEM'.$itemID.'" ';
    echo ">\n";

    $i=0;
    foreach ($files as $file)
      {
	echo '<METS:fptr FILEID="FILE'.$file->item_id.$i.'"/>'."\n";
	//--TODO---each file should be grouped by type,
	//and then with it's deriv. images (if any)
      }

    echo "</METS:div>\n";

    echo "\n</METS:structMap>\n";

    //--------------------
    //END OUTER METS DIV
    //--------------------
    echo "</METS:mets>\n";




  }


  /*
   *Return an array of agents responsible for this resource
   *
   *@param Object $item Omeka record for the item being exported
   *@return array $agents An array of agents and their roles
   */
  private function _getAgents($item)
  {
    $owner = $item->getOwner();
    $currentuser = current_user();
    $rv[]=array($owner->name,"ARCHIVIST","INDIVIDUAL","");
    $rv[]=array($currentuser->name,"CREATOR","INDIVIDUAL","");
    $rv[]=array("Omeka MetsExport Plugin","OTHER","OTHER","The software used to generate this document is called Omeka MetsExport, which operates as a plugin for Omeka. Documentation can be found at http://github/MetsExport/");

    return $rv;
  
  }

  /**
   *Retrieve the slug for a given metadata element set
   *
   *@param string $elementSetName The name of the metadata element
   *set currently being exported
   *@return string $slug The standard shortened form of the 
   *metadata element set name, or "unknown"
   */
  private function _getElementSetSlug($elementSetName)
  {
    //TODO - add support for all common metadata element sets
    switch($elementSetName)
      {
      case 'Dublin Core':
	return 'dc';
	break;
      default:
	return 'unknown';
      }

  }

  /**
   *Determine whether a given metadata element set is
   *recognized or not based on its slug
   *
   *@param
   *@return bool $isOther True if the element set should be of 
   *type "other", false otherwise
   */
  private function _is_type_other($eSSlug)
  {
    if($eSSlug==="unknown")
      return true;
    else
      return false;

  }


}

?>