<?php

class NanoREST
{
  private static $CLASS = "Class";
  private static $FUNCTION = "Function";
  private static $ARGS = "Args";

  public function __construct()
  {
    header("Content-type: application/json");
  }

  public function register($sMethod, $sPath, $sFunction, $aArgs = null)
  {
    $as = explode('/', trim($sPath, "/"));
    $a = &$this->m_a;

    foreach ($as as $s)
    {
      if (!isset($a[$s]))
        $a[$s] = array();

      $a = &$a[$s];
    }

    if (!isset($a[$sMethod]))
      $a[$sMethod] = array();

    $a = &$a[$sMethod];

    $n = strpos($sFunction, "::");

    if ($n === false)
      $a[NanoREST::$FUNCTION] = $sFunction;

    else
    {
      $a[NanoREST::$CLASS] = substr($sFunction, 0, $n);
      $a[NanoREST::$FUNCTION] = substr($sFunction, $n + 2);
    }

    if (!empty($aArgs))
      $a[NanoREST::$ARGS] = is_array($aArgs) ? $aArgs : array($aArgs);
  }

  public function get($sPath, $sFunction = null)
  {
    $this->register("GET", $sPath, $sFunction, array_slice(func_get_args(), 2));
  }

  public function put($sPath, $sFunction = null)
  {
    $this->register("PUT", $sPath, $sFunction, array_slice(func_get_args(), 2));
  }

  public function post($sPath, $sFunction = null)
  {
    $this->register("POST", $sPath, $sFunction, array_slice(func_get_args(), 2));
  }

  public function delete($sPath, $sFunction = null)
  {
    $this->register("DELETE", $sPath, $sFunction, array_slice(func_get_args(), 2));
  }

  public function head($sPath, $sFunction = null)
  {
    $this->register("HEAD", $sPath, $sFunction, array_slice(func_get_args(), 2));
  }

  protected function findMatch(&$a, $asPath, $i, &$recArgs)
  {
    if (count($asPath) == $i)
    {
      if (isset($a[$_SERVER["REQUEST_METHOD"]]))
        return $a[$_SERVER["REQUEST_METHOD"]];
      
      else
        return false;
    }
    
    // exact match
    $sPath = $asPath[$i];
    
    if (isset($a[$sPath]))
    {
      $aResult = $this->findMatch($a[$sPath], $asPath, $i + 1, $recArgs);
      
      if ($aResult !== false)
        return $aResult;
    }
        
    // capture match
    foreach ($a as $sKey => $aSub)
    {
      if ((!empty($sKey)) && ($sKey{0} == '{'))
      {
        // accumulate path info arguments
        $recArgs[substr($sKey, 1, strlen($sKey) - 2)] = $sPath;

        return $this->findMatch($a[$sKey], $asPath, $i + 1, $recArgs);
      }
    }

    return false;
  }

  public function run()
  {
    try
    {
      // get put variables
      $_PUT = array();
      
      if ($_SERVER["REQUEST_METHOD"] == "PUT")
        parse_str(file_get_contents("php://input"), $_PUT);
        
      // in case method is faked
      if (!empty($_GET["_method"]))
      {
        $_SERVER["REQUEST_METHOD"] = $_GET["_method"];
        
        unset($_GET["_method"]);
      }
      
      // parse the path info
      $sURI = $_SERVER["REQUEST_URI"];

      $n = strpos($sURI, '?');

      if ($n !== false)
        $sURI = substr($sURI, 0, $n);

      $sPathInfo = trim(substr($sURI, strlen(dirname($_SERVER["SCRIPT_NAME"])) + 1), '/');

      $asPathInfo = explode('/', $sPathInfo);

      // find the deepest matching registered path
      $recArgs = array();
      $a = $this->findMatch($this->m_a, $asPathInfo, 0, $recArgs);
      
      if ($a === false)
        $this->error(404, $_SERVER["REQUEST_METHOD"]." on $sPathInfo not registered");
      
      // build the command string
      $sCmd = (isset($a[NanoREST::$CLASS])) ? '$a = (new $a[NanoREST::$CLASS]())->$a[NanoREST::$FUNCTION]($this,' 
                                        : '$a = $a[NanoREST::$FUNCTION]($this,';

      if (!empty($a[NanoREST::$ARGS]))
      {
        $asArgs = &$a[NanoREST::$ARGS];

        foreach ($asArgs as $sArg)
        {
                  if ($sArg == "[All]")
          {
            $recAll = array_merge($recArgs, $_GET, $_POST, $_PUT);
            
            foreach ($recAll as $sKey => $val)
            {
              if ($sKey{0} == '_')
                unset($recAll[$sKey]);
              
            }
            
            $sCmd .= '$recAll,';
          }
          
          else if ($sArg == "[Auth]")
          {
            $s = $this->httpHeader("Authorization");
            
            if (empty($s))
            {
              $sUser = $sPW = null;
              
              // check for fakes
              if (!empty($_REQUEST["_user"]))
                $sUser = $_REQUEST["_user"];

              if (!empty($_REQUEST["_pw"]))
                $sPW = $_REQUEST["_pw"];
            }
            
            else
              list($sUser, $sPW) = explode(':', base64_decode(substr($s, 6)));
              
            $sCmd .= '$sUser, $sPW,';
          }
          
          else if (isset($recArgs[$sArg]))
            $sCmd .= "'".$recArgs[$sArg]."',";

          else if (isset($_REQUEST[$sArg]))
            $sCmd .= "'".$_REQUEST[$sArg]."',";

          else if (isset($_PUT[$sArg]))
            $sCmd .= "'".$_PUT[$sArg]."',";

          else
            $this->error(404, "Argument $sArg not sent");
        }
      }

      $sCmd = rtrim($sCmd, ',').");";

      eval($sCmd);

      if (empty($a))
        $this->error(404, "Not found");

      // may just be a boolean to indicate success / failure
      if (gettype($a) == "boolean")
      {
        if ($a)
          $a = ["Status" => "SUCCESS"];

        else
          $a = ["Status" => "ERROR"];
      }

      if (!isset($a["Status"]))
        $a["Status"] = "SUCCESS";

      $sOut = json_encode($a);

      if (empty($sOut))
        $this->error(404, "No JSON found");

      header("Content-length: ".strlen($sOut));

      echo $sOut;
    }

    catch (Exception $ex)
    {
      $this->error($ex->getCode(), $ex->getMessage());
    }
  }

  /**
   * Handle cache modification checks (e-tag and if-modified)
   *
   * @param string $sUnique               a unique identifier for the document
   * @param number|string $lastModified   the last modification date of the document (as string or timestamp)
   */
  public function setModified($sUnique, $dteModified)
  {
    if (gettype($dteModified) == "string")
      $dteModified = strtotime(empty($dteModified) ? "2000-01-01 00:00:00" : $dteModified);
    
    $sETag = gmdate("YmdHis", $dteModified).$sUnique;
    
    // first check for an "if-modified-since" or "if-none-match" header indicating the most recent cached copy
    $sIMS = $this->httpHeader("if-modified-since");
    $sINM = $this->httpHeader("if-none-match");
    
    if (!empty($sIMS))
    {
      $dteCheck = strtotime($sIMS);
    
      if (strtotime($sIMS) >= $dteModified)
        $this->error(304, "Not Modified");
    }
    
    if ((!empty($sINM)) && ($sINM == $sETag))
      $this->error(304, "Not Modified");
          
    // set the last-modified and e-tag header so client knows how to cache
    //header("Last-Modified: ".gmdate("D, d M Y H:i:s", $dteModified)." GMT");
    header("E-Tag: ".$sETag);
  }

  protected function httpHeader($s)
  {
    if (!function_exists('apache_request_headers'))
    {
      $s = "HTML_".strtoupper($s);
  
      if (isset($_SERVER[$s]))
        return $_SERVER[$s];
  
      // last resort - try the environment
      return getenv($s);
    }
  
    else
    {
      global $g_recHeaders;
  
      if (!isset($g_recHeaders))
        $g_recHeaders = apache_request_headers();
  
      return isset($g_recHeaders[$s]) ? $g_recHeaders[$s] : null;
    }
  }
  
  protected function error($nCode, $sMessage, $bIncludeBody = true)
  {
    $sProtocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1');

    header($sProtocol.' '.$nCode.' '.$sMessage);

    if ($bIncludeBody)
      die('{"Status": "ERROR", "Code": '.$nCode.', "Message": "'.str_replace('"', '\\"', $sMessage).'"}');
  }

  private $m_a = array();
};

