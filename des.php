<?php
  class DesComponent {
    // The secretkey is provided by Speedaf
    var $secretkey = "secretkey";
    
    function encrypt($string) {
      $ivArray=array(0x12, 0x34, 0x56, 0x78, 0x90, 0xAB, 0xCD, 0xEF);
      $iv=null;
        foreach ($ivArray as $element)
          $iv .= chr($element);

      $size = mcrypt_get_block_size(MCRYPT_DES, MCRYPT_MODE_CBC);
      $string = $this->pkcs5Pad($string, $size);
      $data = mcrypt_encrypt(MCRYPT_DES, $this->secretkey, $string, MCRYPT_MODE_CBC, $iv);

      $data = base64_encode($data);
      return $data;
    }

 
    function decrypt($string) {

      $ivArray=array(0x12, 0x34, 0x56, 0x78, 0x90, 0xAB, 0xCD, 0xEF);
      $iv=null;
        foreach ($ivArray as $element)
          $iv .= chr($element);

      $string = base64_decode($string);
      //echo("****");
      //echo($string);
      //echo("****");
      $result = mcrypt_decrypt(MCRYPT_DES, $this->secretkey, $string, MCRYPT_MODE_CBC, $iv);
      $result = $this->pkcs5Unpad($result);
        
      return $result;
    }


    function pkcs5Pad($text, $blocksize) { 
        
    $pad = $blocksize - (strlen($text) % $blocksize);

    return $text . str_repeat(chr($pad), $pad);
    } 

 

    function pkcs5Unpad($text) { 

      $pad = ord($text[strlen($text) - 1]);

      if ($pad > strlen($text))
        return false;

      if (strspn($text, chr($pad), strlen($text) - $pad) != $pad)
        return false;

      return substr($text, 0, -1 * $pad);

    }      

  }

  function is_utf8($string) {
    return preg_match('%^(?:
    [\x09\x0A\x0D\x20-\x7E]
    | [\xC2-\xDF][\x80-\xBF]
    | \xE0[\xA0-\xBF][\x80-\xBF]
    | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}
    | \xED[\x80-\x9F][\x80-\xBF]
    | \xF0[\x90-\xBF][\x80-\xBF]{2}
    | [\xF1-\xF3][\x80-\xBF]{3}
    | \xF4[\x80-\x8F][\x80-\xBF]{2}
    )*$%xs', $string);
  }