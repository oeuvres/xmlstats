<?php
/**

Licence LGPL  http://www.gnu.org/licenses/lgpl.html
© 2010 Frederic.Glorieux@fictif.org et École nationale des chartes
© 2012 Frederic.Glorieux@fictif.org 

 */
if (php_sapi_name() == "cli") {
  TagList::doCli();
}
 
class TagList {
  /** start timestamp */
  var $start;
  /** Tags array */
  var $tagMap=array();
  /** Attributes array */
  var $attMap=array();
  /* Segmentation, count tag event */
  var $events=0;
  /* Counter for segmentation, chars */
  var $chars=0;
  /* Counter for verbosity, text bytes */
  var $textBytes=0;
  /* Counter for verbosity, all bytes */
  var $bytes=0;
  /** Counter of xml documents */
  var $docs=0;
  /** SAX, current() element path */
  var $path;
  /** SAX, current() text() */
  var $text;
  /** SAX, stack of attributes to keep them till ellement is cloded */
  var $atts=array();
  /** SAX, current file */
  var $filename;
  /** XML parser */
  var $parser;
  /** List of typing attributes, where value is significant for special counts */
  var $typeAtt=array("nom", "type", "rend", "xml:lang", "level", "option", "function");
  /** flag of inline mode, affecting use of trim in signs count */
  var $inline=false;
  /** Output format */
  var $format="html";
  /** Constructor */
  function __construct() {
    $this->start=microtime(true);
  }
  /**
   * Parse XML to count tags
   */
  function parse($xmlFile) {
    $xml=file_get_contents($xmlFile);
    $this->parser = xml_parser_create();
    xml_parser_set_option  ( $this->parser , XML_OPTION_CASE_FOLDING , false );
    xml_set_character_data_handler($this->parser, array($this, "saxText"));
    xml_set_element_handler($this->parser, array($this, "saxOpen"), array($this, "saxClose"));
    if (!xml_parse($this->parser, $xml)) {
      echo(
        sprintf("Erreur XML: %s , ligne %d",
          xml_error_string(xml_get_error_code($this->parser)),
          xml_get_current_line_number($this->parser)
        )
      );
    }
    $this->bytes += strlen($xml);
    $this->docs++;
    xml_parser_free($this->parser);
  }
  /**
   * Start element, increment tags
   */
  function saxOpen($parser, $name, $atts) {
    // update xpath
    $this->path.="/".$name;
    // keep memory of current atts
    array_push($this->atts, $atts);
    // seems an inline
    if (trim($this->text)) {
      $this->inline=true;
      $this->events++;
      // increment bytes
      $this->textBytes+=strlen($this->text);
      // increment chars, do not trim here, it's an inline tag
      $this->chars += mb_strlen(preg_replace( '/\s+/', ' ' , $this->text), "UTF-8");
    }
    // reset inline mode
    else $this->inline=false;
    // reset text node buffer
    $this->text="";
    $this->openStat($name);
    // attributes
    foreach($atts as $key => $value) {
      if(!isset($this->attMap[$key])) $this->attMap[$key]=array('count'=>0, 'chars'=>0);
      // increment tag count
      $this->attMap[$key]['count']++;
      // take note of char index (should be modified on closing element to update char count)
      $this->attMap[$key]['chars']+= mb_strlen($value, "UTF-8");
    }
    // add entries for typing attributes
    foreach($this->typeAtt as $key) {
      if (!isset($atts[$key])) continue;
      $this->openStat($name .' '.$key.'="'.$atts[$key].'"');
    }
    /*
    foreach($this->idAtt as $key) {
      if (!isset($atts[$key])) continue;
      $this->openStat($name .' @'.$key);
    }
    */
  }
  /**
   * Element end
   */
  function saxClose($parser, $name) {
    // get last atts
    $atts=array_pop($this->atts);
    // if text is not empty, count some things
    if (trim($this->text)) {
      $this->events++;
      // increment chars, if it's an inline tag, do not trim text
      if ($this->inline) $text=$this->text;
      else $text=trim($this->text);
      // increment bytes
      $this->textBytes +=strlen($text);
      $this->chars += mb_strlen(preg_replace( '/\s+/', ' ' , $text), "UTF-8");
    }
    // update chars index
    $this->closeStat($name);
    foreach($this->typeAtt as $key) {
      if (!isset($atts[$key])) continue;
      $this->closeStat($name .' '.$key.'="'.$atts[$key].'"');
    }
    /*
    foreach($this->idAtt as $key) {
      if (!isset($atts[$key])) continue;
      $this->closeStat($name .' @'.$key);
    }
    */
    // reset text buffer
    $this->text="";
    // update path
    $this->path=substr($this->path, 0, strrpos($this->path, '/'));
  }
  /**
   * text node
   */
  function saxText($parser, $data) {
    $this->text.=$data;
  }

  /** increment a tag */
  function openStat($key) {
    // new tag, create entry
    if(!isset($this->tagMap[$key])) $this->tagMap[$key]=array('count'=>0, 'chars'=>0, 'events'=>0);
    // increment tag count
    $this->tagMap[$key]['count']++;
    // recursive tag, may bug, not tested
    // take note of char index (should be modified on closing element to update char count)
    $this->tagMap[$key]['charMark']=$this->chars;
    // take note of events
    $this->tagMap[$key]['eventMark']=$this->events;
  }
  /**
   * Update char content
   */
  function closeStat($key) {
    // recursive tag, may bug, not tested
    if (isset($this->tagMap[$key]['charMark'])) {
      $this->tagMap[$key]['chars']+=$this->chars - $this->tagMap[$key]['charMark'];
      unset ($this->tagMap[$key]['charMark']);
    }
    if (isset($this->tagMap[$key]['eventMark'])) {
      $this->tagMap[$key]['events']+=$this->events - $this->tagMap[$key]['eventMark'];
      unset ($this->tagMap[$key]['eventMark']);
    }
  }

  /**
   * Display tag table
   */
  function table($format='txt', $corpus=null) {
    arsort($this->tagMap);
    arsort($this->attMap);
    $display=array();
    $caption='poids : ' . TagList::sizeH($this->bytes) .
    'o – caractères : '.number_format($this->chars, 0, ',', ' ').
    ' – documents : ' . $this->docs .
//    ' ; document moyen (octets) = ' . number_format($this->bytes / $this->docs, 0, null, " ") .
    ' – segment moyen : '.@number_format($this->chars / $this->events, 1, ',', ' ') . ' caractères' .
    ' – poids des balises : '. @round(100 - 100 * $this->textBytes / $this->bytes). '%'.
    '.';

    if ($format == "txt" || $format=='csv') {
      $display[]=$caption;
      $display[]="\n   balise                      | effectif   | caractères      | % texte     | tx. moy. c. | segmentation ";
      foreach($this->tagMap as $name => $n) {
        $name='<'.$name.'>';
        $name=@str_repeat(" ", 30-mb_strlen( $name , "UTF-8")) . $name;
        $display[]= $name
          .' | '.str_pad(number_format($n['count'], 0, null, " "), 10, " ", STR_PAD_LEFT)
          .' | '.str_pad(number_format($n['chars'], 0, null, " "), 11, " ", STR_PAD_LEFT)
          .' | '.str_pad(round(100 * $n['chars'] / $this->chars),  10, " ", STR_PAD_LEFT).'%'
          .' | '.str_pad(number_format($n['chars'] / $n['count'], 0, null, " "), 10, " ", STR_PAD_LEFT)
          .' | '.str_pad(@round($n['chars']/$n['events']), 10, " ", STR_PAD_LEFT)
        ;
      }
      $display[]= "\n   attribut                    | nombre     | caractères      | moyenne";
      foreach($this->attMap as $name => $n) {
        $name='@'.$name;
        $name=@str_repeat(" ", 30-mb_strlen( $name , "UTF-8")) . $name;
        $display[]= $name
          .' | '.str_pad(number_format($n['count'], 0, null, " "), 10, " ", STR_PAD_LEFT)
          .' | '.str_pad(number_format($n['chars'], 0, null, " "), 11, " ", STR_PAD_LEFT)
          .' | '.str_pad(number_format($n['chars'] / $n['count'], 1, null, " "), 10, " ", STR_PAD_LEFT)
         ;
      }
      $display[]="\n";
    }
    else {
      $display[]= "\n<p> </p>\n<div>".$caption . "</div>";
      $display[]='
<table class="sortable" align="center">
  <tr>
    <th title="Nom de l’élément">balise</th>
    <th title="Nombre d’éléments de ce nom">effectif (n)</th>
    <th title="Texte total dans la balise, en nombre de caractères">texte (c)</th>
    <th title="Par du texte de ce document à l’intérieur de cette balise">part du texte</th>
    <th title="Nombre moyen de caractères par balise">c / n</th>
    <th title="Taille moyenne d’un segment de texte sans interruption, en caractères">segmentation</th>
  </tr>
      ';
      foreach($this->tagMap as $name => $n) {
        $xpath='//'.preg_replace('/ (.*)="(.*)"/', '[@$1=\'$2\']', $name).($corpus?'&amp;corpus='.$corpus:'');
        $display[]='
  <tr>
    <td align="right"><a href="?xpath='.$xpath.'">&lt;'.$name.'&gt;</a></td>
    <td align="right">'.number_format($n['count'], 0, null, " ").'</td>
    <td align="right">'.number_format($n['chars'], 0, null, " ").'</td>
    <td align="right">'.@number_format(100 * $n['chars'] / $this->chars, 2, ",", " ").' %</td>
    <td align="right">'.number_format($n['chars'] / $n['count'], 0, null, " ").'</td>
    <td align="right">'.@round($n['chars'] / $n['events']).'</td>
  </tr>';
      }
      $display[]= '
</table>';

      if (count($this->attMap)) {
        $display[]= '
  <table class="sortable">
    <tr>
      <th>attribut</th>
      <th>effectif</th>
      <th>caractères</th>
      <th>moyenne</th>
    </tr>
        ';
        foreach($this->attMap as $name => $n) {
          $display[]= '
    <tr>
      <td align="right"><a href="?xpath=//@'.$name.($corpus?'&amp;corpus='.$corpus:'').'">@'.$name.'</a></td>
      <td align="right">'.number_format($n['count'], 0, null, " ").'</td>
      <td align="right">'.number_format($n['chars'], 0, null, " ").'</td>
      <td align="right">'.number_format($n['chars'] / $n['count'], 1, null, " ").'</td>
    </tr>';
        }
        $display[]= '
  </table>';
      }
    }
    return implode("\n", $display);
  }

  /** test features */
  static function test($format='html') {
    $docs=array(
      '<ok/>',
      '<unicode>É</unicode>',
      '<indentation>

  <p>
  12
  35
  </p>
         </indentation>',
      '<inline>1234 <i>12345</i> 2345</inline>',
      '<doc> <a>12<b/>12</a> <a>12</a> </doc>',
      '<doc>
  <div>1</div>
  <div type="classA">1</div>
  <div type="classA">2</div>
  <div type="classB">1</div>
</doc>',
    );

    foreach($docs as $xml) {
      if($format == "html") echo '<pre class="code">'.htmlspecialchars($xml)."</pre>";
      else echo $xml;
      $stats=new TagList();
      $stats->parse($xml);
      $stats->table($format);
    }
  }
  /** Help message */
  function help() {
    print '
  <dl>

    <dt>Segment moyen</dt>
    <dd>
    Taille moyenne d’un noeud texte. Pour la typographie,
    il s’agirait du nombre moyen de caractères sans changer de casier (italique, gras, tailles…).
    Au temps des automates de photocompoisition, ce serait le texte entre deux commandes (démarrer italique,
    arrêter italique, démarrer gras…).
    Pour un texte balisé, cet indice peut être significatif de la complexité du document.
    Cela peut se calculer avec la formule xpath suivante
    <code>string-length(normalize-space(/)) div count (//text()[normalize-space(.) != \'\'])</code>.
    Autrement dit, la taille du texte normalisé, divisée par le nombre de noeuds texte non vides.
    </dd>
  </dl>
      ';

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
  static function doCli() {
    $formats='(csv|html)';
    array_shift($_SERVER['argv']); // shift first arg, the script filepath
    if (!count($_SERVER['argv'])) exit("
    usage      : php -f TagList.php src.xml dest.$formats
    src.xml    : if src is  a glob pattern (example \"*.xml\", put quotes in linux to avoid auto expansion)
                 files with this extension will be recursively searched from start point
    dest.{ext} : extension of dest file will decide of the desired format
  ");
    $srcGlob=array_shift($_SERVER['argv']);
    $destFile=array_shift($_SERVER['argv']);
    if (!$destFile) $destFile="TagList.csv";
    $pathinfo=pathinfo($destFile);
    $format=$pathinfo['extension'];
    $stats=new TagList();
    self::scanDir($srcGlob, $stats);
    echo "\n\n";
    // write to file
    echo $stats->table($format);
  }
  /** Recursive glob */
  static function scanDir($srcGlob, $stats) {
    // scan files
    foreach(glob($srcGlob) as $srcFile) {
      echo(" - ".basename($srcFile));
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
