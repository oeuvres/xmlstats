<?php
/**

Licence LGPL  http://www.gnu.org/licenses/lgpl.html
© 2010 Frederic.Glorieux@fictif.org et École nationale des chartes
© 2012 Frederic.Glorieux@fictif.org 


 */

if (php_sapi_name() == "cli") {
  FormList::doCli();
}
 
class FormList {
  /** Xpath for a set of files */
  public static $xpath;
  /** start timestamp */
  public $start;
  /** analyzer mode */
  public $mode;
  /** Current XML DOM document */
  public $xml;
  /** Flag if text should be tokenized */
  public $tokenize;
  /** Index expressions */
  public $locution;
  /** Stop list */
  public $exclude=array();
  /** form count */
  public $formCount;
  /** index */
  public $forms=array();
  /** Default output format */
  public $format="html";
  /** UTF-8 pattern for a word */
  static public $reW='/[\p{L}_][\p{L}\p{Mn}\p{Pd}_]*/iu'; // \x{2019} = ’
  /** SQLite link */
  public $pdo;
  /** Current prepared statement */
  public $ins;
  /** Current bind Param */
  public $bindParam;
  /**
   * Constructor
   */
  function __construct($mode, $exclude=null, $format="html") {
    $this->format=$format;
    $this->start=microtime(true);
    // opération par défaut
    if (!$mode) $mode="tokenize";
    $this->mode=$mode;
    if (count($exclude)) $this->exclude=$exclude;
    if (!@preg_match('/\pL/u', 'À')) {
      // self::$rePonctuation='[.,—–;:?!<>«»]';
      self::$reW='/[âäàæçéèëêïîôœûüùÿ\w][_âäàæçéèëêïîôœûüùÿ\w\-\'’]+/iu';
    }
    // refresh sqlite connection
    // $this->baseCreate();
  }
  /**
   * Parse XML to count
   */
  public function parse($xmlFile, $xpath=null) {
    if (self::$xpath) $xpath=self::$xpath;
  /*
    if (strpos($xml, "\n") === false && strpos($xml, "\r") === false) $xml=file_get_contents($xml);
    $xml=preg_replace('/ xmlns="[^"]*"/', '', $xml, 1);
    $this->xml=new DOMDocument();
    @$this->xml->loadXML($xml, LIBXML_NOENT | LIBXML_NONET | LIBXML_NSCLEAN | LIBXML_NOCDATA | LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOERROR | LIBXML_NOWARNING); // blanks should be kept LIBXML_NOBLANKS,
    unset($xml);
  */
    if (php_sapi_name() == "cli") fwrite(STDERR, "\n$xmlFile#$xpath");
    // pour éviter timeout html
    else echo "\n<!-- $xmlFile#$xpath -->";
    $this->xml=new DOMDocument();
    //
    $xml=preg_replace('/ xmlns="[^"]*"/', '', file_get_contents($xmlFile)) ;
    // il reste des warnings pour les doublons xml:id
    // blanks should be kept LIBXML_NOBLANKS,
    @$this->xml->loadXML($xml, LIBXML_NOENT | LIBXML_NONET | LIBXML_NSCLEAN | LIBXML_NOCDATA | LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOERROR | LIBXML_NOWARNING);

    // hack de hack, buggy !
    // remove the default namespace binding
    // $e = $this->xml->documentElement;
    // $e->removeAttributeNS($e->getAttributeNode("xmlns")->nodeValue,"");
    // ne marche pas
    // $this->xml->documentElement->removeAttribute("xmlns");
    // ne semble pas nécessaire, gardé pour mémoire
    // $this->xml->loadXML($this->xml->saveXML($this->xml));
    // $this->xml->normalize();


    $proc=new DOMXpath($this->xml);

    $nodes = $proc->query($xpath);
    unset($this->xml); // free resources
    unset($proc);
    $text;
    if (is_null($nodes)) return;

    if ($this->pdo) $this->pdo->beginTransaction();
    if ($this->mode == 'nodelist') {
      $this->nodelist($nodes, $this->format);
    }
    else if ($this->mode == "locution") {
      foreach ($nodes as $n) {
        $text=$n->textContent;
        $text=mb_strtolower($text, "UTF-8");
        preg_replace_callback('/([\p{L}_][\p{L}\p{Mn}\p{Pd}_]*|\.)|([,:;?!"()«»])/u', array($this, "tokLoc"), $text);
      }
    }
    /*
    else if ($this->mode == "tokenize" && $this->pdo) {
      foreach ($nodes as $n) {
        $text=$n->textContent.".";
        preg_replace_callback(self::$reW, array($this, "formPush"), mb_strtolower($text, "UTF-8"));
      }
    }
    */
    else if ($this->mode == "tokenize") {
      foreach ($nodes as $n) {
        $text=$n->textContent;
        $text=mb_strtolower($text, "UTF-8");
        preg_replace_callback( '/([\p{L}_][\p{L}\p{Mn}\p{Pd}_]*)|([,.:;?!"()])/iu', array($this, "tokNext"), $text);
      }
    }
    else {
      foreach ($nodes as $n) {
        $text=$n->textContent;
        if ( mb_strlen($text, "UTF-8") > 300) $text=mb_substr($text, 0, 300, "UTF-8").'…';
        // quite nice text normalization, do it after cut, recut after
        $text=preg_replace(array('/\s\s*/', '/^\s\s*/', '/\s\s*$/'), array(' ', '', ''), $text);
        if ( mb_strlen($text, "UTF-8") > 200) $text=mb_substr($text, 0, 200, "UTF-8").'…';
        $this->formPush($text);
      }
    }
    if ($this->pdo) $this->pdo->commit();
  }
  /** pour tests */
  public function noop() {}
  /**
   * Liste de nœuds
   */
  public function nodelist($nodes, $format="html") {
    $getLine=false;
    if (function_exists("DOMElement::getLineNo")) $getLine="<th>ligne</th>";
    if ($format=="html") echo '
<table class="sortable" id="nodelist">
<thead>
  <tr>
    <th>xml:id</th>',$getLine,'
    <th>Contenu</th>
  </tr>
</thead>
<tbody>
';
    $limit=2000;
    $i=$limit;
    foreach ($nodes as $n) {
      $i--;
      if ($format=="html" && !$i) {
        echo '<tr><td colspan="5">… ',$limit,'/',$nodes->length,'</td></tr>';
        break;
      }
      $parent=$n;
      // get an @xml:id
      do {
        if ($parent->nodeType != XML_ELEMENT_NODE) continue;
        $id=$parent->getAttribute('xml:id');
        if (!$id) continue;
        break;
      }
      while ($parent = $parent->parentNode);

      if ($format=="html") {
        echo "<tr><th>$id</th>";
        if ($getLine) echo  "<td>", $n->getLineNo(),"</td>";
        echo "<td>", substr($n->textContent, 0, 2000) ,"</td></tr>\n";
      }
      else if ($format=="csv") {
        echo "$id";
        if ($getLine) echo  ',', $n->getLineNo();
        echo ',"', strtr($n->textContent, array('"', '\"')),'"',"\n";
      }
      else {
        echo preg_replace('/\s+/', ' ', trim($n->textContent)),"\n";
      }
    }
    if ($format == "html") echo '
</tbody>
</table>
    ';

  }
  /**
   * Push a form
   */
  public function formPush($form) {
    if(!isset($this->forms[$form])) $this->forms[$form]=0;
    $this->forms[$form]++;
    // $this->ins->execute(array($form));
    $this->formCount++;
  }
  /**
   * react to a token event
   */
  public function tokNext($matches) {
    if (isset($matches[2]) && !isset($this->exclude[$matches[2]])) return $this->formPush($matches[2]); // ponctuation
    if (isset($this->exclude[$matches[1]])) return; // stop word
    $this->formPush($matches[1]);
  }
  /**
   * react to a token event, extract locutions
   */
  public function tokLoc($matches) {
    // ponctuation, reset locution
    if (isset($matches[2])) {
      $this->locution="";
      return;
    }
    if (isset($matches[1])) $w=$matches[1];
    else return;

    // stop word, go next
    if (isset($this->exclude[$w])) {
      if ($this->locution) $this->locution.=" ".$w;
      return;
    }
    // a locution is started
    if ($this->locution) {
      $this->formPush($this->locution." ".$w);
      $this->locution="";
    }
    $this->locution=$w;
  }

  /**
   * Display forms as a list
   *
   */
  function table($format='txt', $include=null) {
    if (!$this->pdo && !count($this->forms)) return;
    // Littré. DOM only, 40Mo 5,49 s. Array() : 137Mo, 46s. SQLIte 86,50Mo 222s.
    if ($format=="html") {
      echo "\n<!-- ", XmlStats::sizeH(memory_get_peak_usage(true)) , "o ", number_format( (microtime(true) - $this->start), 4, null, " ") , " s. -->\n";
      echo '
<table class="sortable" id="XmlVoc">
  ';
    }

    $limit=1000;
    // sqlite base
    if($this->pdo) {
      print'
  <thead>
    <tr>
      <th>graphie</th>
      <th>effectif</th>
    </tr>
  </thead>
  <tbody>
  ';
      $i=$limit;
      foreach($this->pdo->query('SELECT word, count FROM wc ORDER BY count DESC') as $row) {
        $i--;
        print '
    <tr>
      <td class="string">'.$row['word'].'</td>
      <td align="right">'.number_format($row['count'], 0, null, " ").'</td>
    </tr>';
        if (!$i) {
          print '
    <tr>
      <td class="string">… plus de '.$limit.'</td>
    </tr>
          ';
          break;
        }
      }

    }
    // indexed in array()
    else {
      // ksort($this->index); // a first sort has no effect
      arsort($this->forms, SORT_NUMERIC);
      if ($format=='html') {
        echo '
  <thead>
    <tr>
      <th>formes</th>
      <th>occurences</th>
    </tr>
    <tr>
      <th align="right" title="Nombre total de formes différentes">' , number_format(count($this->forms), 0, null, " ") , '</th>';
      if ($this->formCount) echo '<th align="right" title="Nombre total des occurrences (sans la ponctuation)">' , number_format($this->formCount, 0, null, " ") , '</th>';
      else echo "<th> </th>";
      echo '
    </tr>
  </thead>
  <tbody>';
      }
      else if ($format == 'csv') {
        echo "formes,occurrences\n";
      }
      $i=$limit;
      foreach($this->forms as $form => $count) {
        $i--;
        $count=number_format($count, 0, null, " ");
        if ($format == 'html' && $form) {
          echo '
      <tr>
        <td class="string">' , $form , '</td>
        <td align="right">' , $count , '</td>
      </tr>';
          if (!$i) {
            print '
      <tr>
        <td class="string" colspan="2">… plus de '.$limit.'</td>
      </tr>
            ';
            break;
          }
        }
        else if ($format == 'csv') {
          echo '"', $form, '",',$count,"\n";
        }
        else if ($format == 'txt') {
          echo $form,"\n";
        }
      }
    }


    if ($format == "html") print '
  </tbody>
</table>';

  }
  /**
   * If big problem on memory footprint, this could help, except it is longer
   */
  public function baseCreate() {
  // pdo persistant ?
    // ?? si base méméoire pose problème
    // $base=tempnam(null, "XmlStatsSQL_");
    // $this->pdo = new PDO("sqlite:$base");
    $this->pdo = new PDO("sqlite::memory:");
    /*
// For session persistency
$pdo = new PDO(
    'sqlite::memory:',
    null,
    null,
    array(PDO::ATTR_PERSISTENT => true)
);
    */
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $create="
DROP TABLE IF EXISTS wc;
CREATE TABLE wc (
  word TEXT PRIMARY KEY,
  count INTEGER DEFAULT 1
);

-- increment counter when word already set
CREATE TRIGGER wc_inc before INSERT ON wc
when (SELECT 1 FROM wc WHERE word=new.word) = 1
begin
  UPDATE wc SET
    count = count + 1
    WHERE word=new.word;
  SELECT raise(IGNORE);
end;

    ";
    $this->pdo->exec($create);
    $this->ins = $this->pdo->prepare("INSERT OR IGNORE INTO wc (word) VALUES (?)");
  }

  /** test features */
  static function test($format="html") {
    $docs=array(
      '<doc>
  <p>Le petit chat est mort, à l’aube.</p>
  <p>Le petit d\'homme a tort, en général.</p>
</doc>',
    );

    foreach($docs as $xml) {
      if($format == "html") echo '<pre class="code">'.htmlspecialchars($xml)."</pre>";
      else echo $xml;
      $stats=new FormList("text");
      $stats->parse($xml, "//p");
      print "texte complet";
      $stats->table($format);

      $stats=new FormList("tokenize");
      $stats->parse($xml, "//p");
      print "mots séparés";
      $stats->table($format);

      $stats=new FormList("locution");
      $stats->parse($xml, "//p");
      print "locutions";
      $stats->table($format);

      $stats=new FormList("tokenize", array("le"=>'', "à"=>'', "l"=>'', "d"=>'', "a"=>'', "en"=>'', "est"=>''));
      $stats->parse($xml, "//p");
      print "mots filtrés";
      $stats->table($format);

      $stats=new FormList("locution", array("le"=>'', "à"=>'', "l"=>'', "d"=>'', "a"=>'', "en"=>'', 'est'=>''));
      $stats->parse($xml, "//p");
      print "locutions";
      $stats->table($format);
    }
  }
  /** An help message */
  static function help() {

  }
  static function doCli() {
    $formats='(csv|html)';
    array_shift($_SERVER['argv']); // shift first arg, the script filepath
    if (!count($_SERVER['argv'])) exit("
    usage      : php -f FormList.php src.xml xpath
    src.xml    : if src is  a glob pattern (example \"*.xml\", put quotes in linux to avoid auto expansion)
                 glob patterns will be recursively searched from start point
  ");
    $srcGlob=array_shift($_SERVER['argv']);
    self::$xpath=array_shift($_SERVER['argv']);
    $destFile=array_shift($_SERVER['argv']);
    if (!$destFile) $destFile="FormList.csv";
    $pathinfo=pathinfo($destFile);
    $format=$pathinfo['extension'];
    $stats=new FormList("value");
    self::scanDir($srcGlob, $stats);
    // write to file
    $stats->table($format);
  }
  /** Recursive glob */
  static function scanDir($srcGlob, $stats) {
    // scan files
    foreach(glob($srcGlob) as $srcFile) {
      $stats->parse($srcFile);
    }
    $pathinfo=pathinfo($srcGlob);
    if (!$pathinfo['dirname']) $pathinfo['dirname']=".";
    foreach( glob( $pathinfo['dirname'].'/*', GLOB_ONLYDIR) as $srcDir) {
      self::scanDir($srcDir.'/'.$pathinfo['basename'], $stats);
    }
  }
}

?>
