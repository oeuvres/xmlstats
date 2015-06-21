<?php // encoding="UTF-8"
/**
Diverses statistiques pour renseigner l'étendue d'un fichier XML

© 2012, <a href="http://algone.net/">Algone</a>,
<a href="http://www.cecill.info/licences/Licence_CeCILL-C_V1-fr.html">licence CeCILL-C</a>
(LGPL compatible droit français)

<ul>
  <li>2012 [FG] <a onmouseover="this.href='mailto'+'\x3A'+'frederic.glorieux'+'\x40'+'algone.net'">Frédéric Glorieux</a></li>
</ul>



*/

// Start session now, in hope that file will be included before output
session_cache_limiter(false); // important to get correct cache headers
session_start();

// Put some conf
if(file_exists($file=dirname(__FILE__).'fr.stop')) xmlstats::$stopList['Français, mots vides']=realpath($file);
if(file_exists($file=dirname(__FILE__).'/../la/la.stop')) xmlstats::$stopList['Latin, mots vides']=realpath($file);
if(file_exists(dirname(__FILE__).'/conf.php')) include_once(dirname(__FILE__).'/conf.php');

include dirname(__FILE__).'/TagList.php';
include dirname(__FILE__).'/FormList.php';

// give time and memory
ini_set("max_execution_time", 30000);
ini_set('memory_limit', -1);

class xmlstats {
  /** Set of xml files to propose */
  static public $glob=array();
  /** Set of stopWords */
  static public $stopList=array();
  /** TimeStamp of corpus */
  static public $lastModified=0;
  /** A cache dir, for corpus or session */
  static public $cacheDir;
  /** If a corpus is requested */
  static private $corpus;
  /**
   *
   */
  static function head() {

  }
  /**
   * html div to use object.
   * Allow different output format, especially csv or txt
   */
  static function body() {
    if (!isset($_SESSION['xml'])) $_SESSION['xml']=array();
    // corpus requested, add files from filesystem to session
    $corpus=null;
    // si pas de dossier cache en conf, prendre tmp
    if (!self::$cacheDir) self::$cacheDir=sys_get_temp_dir()."/xmlstats/";
    if ( isset($_GET['corpus']) && isset(self::$glob[$_GET['corpus']]) ) {
      $corpus=$_REQUEST['corpus'];
      // configurer le dossier de cache pour ce corpus
      self::$cacheDir.="$corpus/";
      if (!file_exists(self::$cacheDir)) mkdir(self::$cacheDir, 0777, true);
      chmod (self::$cacheDir, 0777);
      // empty the session
      self::sessionClean();
      foreach(glob(self::$glob[$corpus]) as $file) $_SESSION['xml'][ basename($file)]=$file;
    }
    // add upload file to session
    if (isset($_FILES['xml']) && ($name=$_FILES['xml']['name']) ) {
      $file=tempnam(null, "xmlstats_");
      if (isset($_SESSION['xml'][$name]) && file_exists($_SESSION['xml'][$name])) unlink($_SESSION['xml'][$name]);
      move_uploaded_file($_FILES['xml']['tmp_name'], $file);
      $_SESSION['xml'][$name]=$file;
      ksort($_SESSION['xml']);
    }
    // Actions
    $xpath=(isset($_REQUEST['xpath'])) ? $_REQUEST['xpath'] : null;

    if (isset($_REQUEST['value'])) $mode="value";
    else if (isset($_REQUEST['tokenize'])) $mode="tokenize";
    else if (isset($_REQUEST['locution'])) $mode="locution";
    else if (isset($_REQUEST['nodelist'])) $mode="nodelist";
    else $mode="tokenize";

    // display forms
    $format=(isset($_REQUEST['format'])) ? $_REQUEST['format'] : null;
    if ($format!="csv" && $format!="txt") $format="html";
    if ($format=='html') {
      self::formFile();
      self::formDo();
    }
    // get a last modified date
    foreach($_SESSION['xml'] as $name=>$file) {
      if (filemtime($file) > self::$lastModified) self::$lastModified=filemtime($file);
    }
  /*
    // regénérer si les fichiers source ont changé
    foreach(glob(dirname(__FILE__).'/*.php') as $file) {
      if (filemtime($file) > self::$lastModified) self::$lastModified=filemtime($file);
    }
  */


    // load a stop list
    $exclude=null;
    if (isset($_REQUEST['stop']) && isset(xmlstats::$stopList[$_REQUEST['stop']])) {
      $file=xmlstats::$stopList[$_REQUEST['stop']];
      $exclude=explode("\n", preg_replace('/#.*/','',file_get_contents($file)));
      $exclude=array_flip($exclude);
      unset($exclude['']);
    }

    // choose action
    if (isset($_REQUEST['help'])) {
      FormList::help();
      FormList::test();
      TagList::help();
      TagList::test();
    }
    // Taglist with cache for corpus
    else if ($corpus && isset($_REQUEST['taglist'])) {
      $cacheFile=self::$cacheDir."taglist.$format";
      @chmod($cacheFile, 0666);
      if (!file_exists($cacheFile) || filemtime($cacheFile) < self::$lastModified || isset($_REQUEST['nocache'])) {
        $stats=new TagList($format);
        foreach(glob(self::$glob[$corpus]) as $file) {
          if ($format == "html") print "\n<!-- ".basename($file)." -->"; // output something for timeout
          else header("XmlStats-File:".basename($file));
          $stats->parse( $file);
        }
        ob_start();
        $stats->table($format, $corpus);
        $contents=ob_get_contents();
        ob_end_clean();
        file_put_contents($cacheFile, $contents);
      }
      include $cacheFile;
    }
    // FormList with cache for known corpus
    else if ($corpus && $xpath) {
      $stop=(isset($_REQUEST['stop'])) ? $_REQUEST['stop'] : null;
      $cacheFile=self::$cacheDir.urlencode($xpath.'_'.$mode.'_'.$stop).".$format";
      if (!file_exists($cacheFile) || filemtime($cacheFile) < self::$lastModified || isset($_REQUEST['nocache'])) {
        $stats=new FormList($mode, $exclude, $format);
        $cache=fopen($cacheFile, "w");
        @chmod($cacheFile, 0666);
        $contents=null;
        foreach(glob(self::$glob[$corpus]) as $file) {
          // avoid timeout
          if ($format == "html") echo "\n<!-- ",basename($file)," -->";
          else if(!$contents) header("XmlStats-File:".basename($file));
          ob_start();
          $stats->parse( $file, $xpath);
          $contents=ob_get_contents();
          fwrite($cache, $contents);
          ob_end_clean();
          echo $contents;
        }
        ob_start();
        $stats->table($format);
        $contents=ob_get_contents();
        fwrite($cache, $contents);
        ob_end_clean();
        echo $contents;
        fclose($cache);
      }
      else include $cacheFile;
    }
    else if (isset($_REQUEST['taglist'])) {
      echo "<p>Liste des balises au format : $format</p>";
      $stats=new TagList($format);
      foreach($_SESSION['xml'] as $name=>$file){
        if ($format == "html") echo "\n<!-- ",basename($file)," -->";
        else header("XmlStats-File:".basename($file));
        $stats->parse($file);
      }
      echo $stats->table($format);
    }
    else if ($xpath) {
      $stats=new FormList($mode, $exclude, $format);
      foreach($_SESSION['xml'] as $name=>$file) {
        if ($format == "html") echo "\n<!-- ",basename($file)," -->";
        else header("XmlStats-File:".basename($file));
        $stats->parse($file, $xpath);
      }
      $stats->table($format);
    }
    else {
      self::welcome();
    }
    // javascript
    if ($format=="html"){
      print '<script type="text/javascript" src="Sortable.js">//</script>';
    }
    else exit;
  }

  /**
   *
   */
  static function formDo() {
    $xpath= (isset($_REQUEST['xpath'])) ? str_replace('"', '&quot;', $_REQUEST['xpath']) : null;
    // déjouer les magic quotes
    if (get_magic_quotes_gpc()) $xpath=stripslashes($xpath);
    $corpus=(isset($_REQUEST['corpus'])) ? $_REQUEST['corpus'] : '';
    $stop=(isset($_REQUEST['stop'])) ? $_REQUEST['stop'] : '';
    echo '
    <p/>
    <form method="GET" name="taglist">
      <input type="hidden" name="corpus" value="' , $corpus , '"/>
      <input name="taglist" type="submit" value="Table des balises"/>
    </form>
    <form method="GET" name="xpath">
      <input type="hidden" name="corpus" value="' , $corpus , '"/>
      <label>Expression Xpath <input type="text" size="0" name="xpath" value="' , $xpath , '"/></label>';

    $stop=(isset($_REQUEST['stop'])) ? $_REQUEST['stop'] : null;
    if (count(xmlstats::$stopList)) {
      echo '
<label> – Filtre
  <select name="stop">
    <option></option>
';
    foreach (xmlstats::$stopList as $name=>$file) {
      echo "\n<option";
      if ($stop == $name) echo ' selected="selected"';
      echo ">$name</option>";
    }
    echo '
  </select>
</label>';
    }
    print '

<label>Format
  <select name="format">
    <option/>
    <option value="txt">Liste (txt)</option>
    <option value="csv">Tableur (csv)</option>
  </select>
</label>
<button type="submit" name="tokenize" value="1">Mots fréquents</button>
</form>
<p> </p>
';
/* Option trop lourdes en général
<button type="submit" name="nodelist" value="1" title="">Liste de contenus</button>
<button type="submit" name="value" value="1" title="Table triée des valeurs">Table de valeurs</button>
<button type="submit" name="tokenize" value="1">Table de mots</button>
<button type="submit" name="locution" value="1">Table de locutions</button>

 */
  }

  /**
   * The form to manage filelists
   */
  static function formFile() {
    print '
<p>Analyser le contenu d’un document XML (balises, texte, mots)</p>

<form enctype="multipart/form-data" method="POST" name="upload" id="upload">
  <label>Fichiers XML en session <input type="file" size="50" name="xml" id="file_xml" onchange="this.form.submit()"/></label>
  <input name="ajouter" type="submit" value="Ajouter"/>
  <input name="vider" type="submit" value="Vider"/>
</form>
    ';
    if(isset($_POST['vider'])) {
      self::sessionClean();
      print "<h2>Session vidée</h2>";
    }
    else {
      echo "<div>";
      foreach($_SESSION['xml'] as $name=>$file)
        echo " – $name (" , self::sizeH(filesize($file)) , "o)";
      echo "</div>";
    }
  }
  /**
   * Empty the session of filelist
   */
  static function sessionClean() {
    // try to delete tmp files
    if (isset($_SESSION['xml'])) foreach($_SESSION['xml'] as $file) {
      if (strpos(basename($file), 'xmlstats_')!==0); // not one of our temp file
      else if (!file_exists($file)); // file do not exists
      else unlink($file);
    }
    $_SESSION['xml']=array();
  }

  /**
   *
   */
  static function action() {

  }

  /**
   * Welcome message
   */
  static function welcome() {
    print '
<h2>Pourquoi</h2>
<ul>
  <li>2 Mo d’XML, cela fait un texte de combien de caractères ?</li>
  <li>Est-ce que la balise &lt;toto> est vraiment utilisée ?</li>
  <li>Quelle est la part du texte cité (ex : &lt;quote>) ?</li>
  <li>Quels sont les mots les plus fréquents du corpus ?</li>
  <li>Comment repérer les erreurs de termes dans un attribut ?</li>
</ul>
';
    if (count(self::$glob)) {
      print '<h2>Exemples</h2>
      <ul>';
      foreach(self::$glob as $name => $file) print '<li><a href="?corpus='.$name.'&taglist=">'.$name.'</a></li>';
      print '</ul>';
    }
  }

  /**
   * Display nicer File size
   */
  static function sizeH ($size, $mod=1024) {
    $units=array('', 'K', 'M', 'G', 'T', 'P');
    for ($i = 0; $size > $mod; $i++) {
        $size /= $mod;
    }
    $num=round($size, 2);
    $num=number_format($num, 2, ',', ' ');
    // str_repeat(' ', 9 - mb_strlen($num)) ? espaces ?
    return $num . ' ' . $units[$i];
  }

}

// Generate special formats data
$format=(isset($_REQUEST['format'])) ? $_REQUEST['format'] : null;
// specific format, generate and exit, break the caller
if($format=="txt") {
  header("Content-type: text/plain; charset=UTF-8");
  xmlstats::body();
  exit;
}
if($format=="csv") {
  header ("Content-Type: application/csv");
  $corpus=(isset($_REQUEST['corpus']) && $_REQUEST['corpus']) ? $_REQUEST['corpus'] : 'xmlstats';
  header("Content-Disposition: attachment; filename=$corpus.csv");
  xmlstats::body();
  exit;
}

// included file, do nothing
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) != realpath(__FILE__));
else if (isset($_SERVER['ORIG_SCRIPT_FILENAME']) && realpath($_SERVER['ORIG_SCRIPT_FILENAME']) != realpath(__FILE__));
// direct command line call, work
else if (php_sapi_name() == "cli") {
  array_shift($_SERVER['argv']); // shift first arg, the script filepath

  if (!count($_SERVER['argv'])) {
    echo "Quel fichier ou dossier traiter ? Exemple : ../corpus/*.xml\n";
    $glob = trim(fgets(STDIN));
  }
  else $glob=$_SERVER['argv'][0];
  if ($glob == 'test') {
    xmlxtats::test();
    exit;
  }
  echo "\nStatistiques XML pour $glob";
  $stats=new xmlstats();
  foreach(glob($glob) as $file) {
    echo "\n$file";
    $stats->parse($file);
  }
  $stats->report();
}
// direct http call
else {
?>
<html>
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Statistiques XML</title>
    <link rel="stylesheet" type="text/css" href="http://svn.code.sf.net/p/obvil/code/dynhum/html.css" />
    <link rel="stylesheet" type="text/css" href="http://svn.code.sf.net/p/obvil/code/theme/obvil.css" />
  </head>
  <body>
    <div id="center">
      <header id="header">
        <h1>
          <a href="../">Développements</a>
        </h1>
        <a class="logo" href="http://obvil.paris-sorbonne.fr/developpements/"><img class="logo" src="http://svn.code.sf.net/p/obvil/code/theme/img/logo-obvil.png" alt="OBVIL"></a>
      </header>
      <div id="contenu">
        <h1><a href=".">XML stats</a></h1>
        <p class="byline">par Frédéric Glorieux</p>
    <!-- XML STATS -->
    <?php
    xmlstats::body(); ?>
  </body>
</html>
<?php
}




?>
