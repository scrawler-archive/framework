<?php
/**
 * PHP session overridrr
 *
 * @package: Scrawler
 * @author: Pranjal Pandey
 */
namespace Scrawler\Service;

use Scrawler\Scrawler;

class Session
{
    private $flash_data;
    private $flash_data_var;
    private $db;
    private $lock_timeout;
    private $lock_to_ip;
    private $lock_to_user_agent;
    private $session_lifetime;
    private $table_name;
    private $security_code;
    private $read_only = false;

    public function __construct($security_code)
    {
        $this->db = Scrawler::engine()->db();

        try {
            error_reporting(E_ALL ^ E_WARNING);
        // make sure session cookies never expire so that session lifetime
            // will depend only on the value of $session_lifetime
            ini_set('session.cookie_lifetime', 0);

            // tell the browser not to expose the cookie to client side scripting
            // this makes it harder for an attacker to hijack the session ID
            ini_set('session.cookie_httponly', 1);

            // make sure that PHP only uses cookies for sessions and disallow session ID passing as a GET parameter
            ini_set('session.use_only_cookies', 1);

            // instruct the session module to only accepts valid session IDs generated by the session module and rejects
            // any session ID supplied by users
            ini_set('session.use_strict_mode', 1);
       

        // get session lifetime
        $this->session_lifetime = ini_get('session.gc_maxlifetime');

        // we'll use this later on in order to try to prevent HTTP_USER_AGENT spoofing
        $this->security_code = $security_code;
 
        // some other defaults
        $this->lock_to_user_agent = true;
        ;
        $this->lock_to_ip = false;

        // register the new handler
        session_set_save_handler(
            array(&$this, 'open'),
            array(&$this, 'close'),
            array(&$this, 'read'),
            array(&$this, 'write'),
            array(&$this, 'destroy'),
            array(&$this, 'gc')
        );

        // if a session is already started, destroy it first
        if (session_id() !== '') {
            session_destroy();
        }


        // the name for the session variable that will be used for
        // holding information about flash data session variables
        $this->flash_data_var = '_scrawler_session_flash_data_ec4albunk';

        // assume no flash data
        $this->flash_data = array();

        // if any flash data exists
        if (isset($_SESSION[$thFis->flash_data_var])) {

            // retrieve flash data
            $this->flash_data = unserialize($_SESSION[$this->flash_data_var]);

            // destroy the temporary session variable
            unset($_SESSION[$this->flash_data_var]);
        }

        // handle flash data after script execution
        register_shutdown_function(array($this, '_manage_flash_data'));
    }finally{
        error_reporting(E_ALL);
    }
    }

    /**
     * Function to start session when req
     */
    public function start()
    {
        // start session
        session_start();
    }

    /**
    *  Custom close() function
    *
    *  @access private
    */
    public function close()
    {
        return  true;
    }

    /**
     *  Custom destroy() function
     *
     *  @access private
     */
    public function destroy($session_id)
    {
        $session = $this->db->find('session', 'sessionid  LIKE ?', [$session_id]);
        $this->db->delete($session);
        return true;
    }

    /**
     *  Custom gc() function (garbage collector)
     *
     *  @access private
     */
    public function gc()
    {
        $sessions = $this->db->find('session', 'session_expire < ?', [time()]);
        $this->db->deleteAll($sessions);
        return true;
    }

    /**
    *  Custom open() function
    *
    *  @access private
    */
    public function open()
    {
        return true;
    }

    /**
     *  Custom read() function
     *
     *  @access private
     */
    public function read($session_id)
    {
        $hash = $_SERVER['HTTP_USER_AGENT'].$this->security_code;
        $session = $this->db->findOne('session', 'sessionid = ? AND session_expire > ?  AND hash = ?', [$session_id, time(), md5($hash)]);
        if ($session == null) {
            return '';
        }
        return $session->session_data;
    }

    public function regenerate_id()
    {

        // regenerates the id (create a new session with a new id and containing the data from the old session)
        // also, delete the old session
        session_regenerate_id(true);
    }

    public function flash($name, $value=NULL)
    {
        if(isset($name)){
            $_SESSION[$name] = $value;
        }else{
            return $_SESSION[$name];
            $this->clear($name);
        }

        // set session variable

        // initialize the counter for this flash data
        $this->flash_data[$name] = 0;
    }

    /**
    *  Deletes all data related to the session.
    *
    *  @return void
    */
    public function stop()
    {

        // if a cookie is used to pass the session id
        if (ini_get('session.use_cookies')) {

            // get session cookie's properties
            $params = session_get_cookie_params();

            // unset the cookie
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        // destroy the session
        session_unset();
        session_destroy();
    }

    /**
     *  Custom write() function
     *
     *  @access private
     */
    public function write($session_id, $session_data)
    {
        $hash = $_SERVER['HTTP_USER_AGENT'].$this->security_code;

        $session = $this->db->findOne('session', 'sessionid  LIKE ?', [$session_id]);
        if ($session == null) {
            $session = $this->db->create('session');
        }
        $session->sessionid = $session_id;
        $session->hash = md5($hash);
        $session->session_data = $session_data;
        $session->session_expire = time() + $this->session_lifetime;
        $this->db->save($session);
        return true;
    }
    
    /**
    *  Manages flash data behind the scenes
    *
    *  @access private
    */
    public function _manage_flash_data()
    {

        // if there is flash data to be handled
        if (!empty($this->flash_data)) {

            // iterate through all the entries
            foreach ($this->flash_data as $variable => $counter) {

                // increment counter representing server requests
                $this->flash_data[$variable]++;

                // if this is not the first server request
                if ($this->flash_data[$variable] > 1) {

                    // unset the session variable
                    unset($_SESSION[$variable]);

                    // stop tracking
                    unset($this->flash_data[$variable]);
                }
            }

            // if there is any flash data left to be handled
            if (!empty($this->flash_data)) {

                // store data in a temporary session variable
                $_SESSION[$this->flash_data_var] = serialize($this->flash_data);
            }
        }
    }

    public function isset($key){
        return isset($_SESSION[$key]);
    }

    public function __set($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    public function __get($key)
    {
        return $_SESSION[$key];
    }

    public function clear($key){
        unset($_SESSION[$key]);
    }
}
