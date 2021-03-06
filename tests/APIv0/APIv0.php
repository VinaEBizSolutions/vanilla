<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2014 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace VanillaTests\APIv0;

use Garden\Http\HttpClient;
use Garden\Http\HttpResponse;
use PDO;

/**
 * The API client for Vanilla's API version 0.
 */
class APIv0 extends HttpClient {

    /**
     * @var string The API key for making calls to the special test helper script.
     */
    private static $apiKey;

    /**
     * @var array The current config from the install.
     */
    private $config;


    /**
     * @var array The user context to make requests with.
     */
    private $user;

    /**
     * APIv0 constructor.
     */
    public function __construct() {
        parent::__construct();
        $this
            ->setBaseUrl($_ENV['baseurl'])
            ->setThrowExceptions(true);
    }

    /**
     * Get the name of the database for direct access.
     *
     * @return string Returns the name of the database.
     */
    public function getDbName() {
        $host = parse_url($this->getBaseUrl(), PHP_URL_HOST);

        if (isset($_ENV['dbname'])) {
            $dbname = $_ENV['dbname'];
        } else {
            $dbname = preg_replace('`[^a-z]`i', '_', $host);
        }
        return $dbname;
    }

    /**
     * Get the username used to connect to the test database.
     *
     * @return string Returns a username.
     */
    public function getDbUser() {
        return $_ENV['dbuser'];
    }

    /**
     * Get the password used to connect to the test database.
     *
     * @return string Returns a password.
     */
    public function getDbPassword() {
        return $_ENV['dbpass'];
    }

    /**
     * Get a config value.
     *
     * @param string $key The dot-separated config key.
     * @param mixed $default The value to return if there is no config setting.
     * @return mixed Returns the config setting or {@link $default}.
     */
    public function getConfig($key, $default = null) {
        return valr($key, $this->config, $default);
    }

    /**
     * Get the path to the config file for direct access.
     *
     * @return string Returns the path to the database.
     */
    public function getConfigPath() {
        $host = parse_url($this->getBaseUrl(), PHP_URL_HOST);
        $path = PATH_ROOT."/conf/$host.php";
        return $path;
    }

    /**
     * Get a connection to the database.
     *
     * @return \PDO Returns a connection to the database.
     */
    public function getPDO() {
        static $pdo;

        if (!$pdo) {
            $options = [
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND  => "set names 'utf8'"
            ];
            $pdo = new PDO("mysql:host=localhost", $this->getDbUser(), $this->getDbPassword(), $options);

            $dbname = $this->getDbName();
            $r = $pdo->query("show databases like '$dbname'", PDO::FETCH_COLUMN, 0);
            $dbnames = $r->fetchColumn(0);

            if (!empty($dbnames)) {
                $pdo->query("use `$dbname`");
            }
        }

        return $pdo;
    }

    /**
     * Get the transient key for a user.
     *
     * @param int $userID The ID of the user to get the transient key for.
     * @return string Returns the transient key for the user.
     */
    public function getTK($userID) {
        $user = $this->queryOne("select * from GDN_User where UserID = :userID", ['userID' => $userID]);
        if (empty($user)) {
            return '';
        }
        $attributes = (array)dbdecode($user['Attributes']);
        if (empty($attributes['TransientKey'])) {
            $attributes['TransientKey'] = randomString(20);
            $r = $this->query(
                "update GDN_User set Attributes = :attributes where UserID = :userID",
                ['attributes' => dbencode($attributes), 'userID' => $userID],
                true
            );
        }
        return val('TransientKey', $attributes, '');
    }

    /**
     * {@inheritdoc}
     */
    public function handleErrorResponse(HttpResponse $response, $options = []) {
        if ($this->val('throw', $options, $this->throwExceptions)) {
            $body = $response->getBody();
            if (is_array($body)) {
                $message = $this->val(
                    'Exception',
                    $body,
                    $this->val('message', $body, $response->getReasonPhrase())
                );
            } else {
                $message = $response->getRawBody();
            }
            throw new \Exception($message.' ('.$response->getStatusCode().')', $response->getStatusCode());
        }
    }

    /**
     * Install Vanilla.
     *
     * @param string $title The title of the app.
     */
    public function install($title = '') {
        $this->createDatabase();

        // Touch the config file because hhvm runs as root and we don't want the config file to have those permissions.
        $configPath = $this->getConfigPath();
        touch($configPath);
        chmod($configPath, 0777);
        $apiKey = sha1(openssl_random_pseudo_bytes(16));
        $this->saveToConfigDirect(['Test.APIKey' => $apiKey]);
        self::setAPIKey($apiKey);


//        $dir = dirname($configPath);
//        passthru("ls -lah $dir");

        // Install Vanilla via cURL.
        $post = [
            'Database-dot-Host' => 'localhost',
            'Database-dot-Name' => $this->getDbName(),
            'Database-dot-User' => $this->getDbUser(),
            'Database-dot-Password' => $this->getDbPassword(),
            'Garden-dot-Title' => $title ?: 'Vanilla Tests',
            'Email' => 'travis@example.com',
            'Name' => 'travis',
            'Password' => 'travis',
            'PasswordMatch' => 'travis'
        ];

        $r = $this->post('/dashboard/setup.json', $post);

        if (!$r['Installed']) {
            throw new \Exception("Vanilla did not install");
        }

        // Get some configuration information.
        $config = $this->saveToConfig([]);
    }

    /**
     * Encode an array in a format suitable for a cookie header.
     *
     * @param array $array The cookie value array.
     * @return string Returns a string suitable to be passed to a cookie header.
     */
    public static function cookieEncode(array $array) {
        $pairs = [];
        foreach ($array as $key => $value) {
            $pairs[] = "$key=".rawurlencode($value);
        }

        $result = implode('; ', $pairs);
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function createRequest($method, $uri, $body, array $headers = [], array $options = []) {
        $request = parent::createRequest($method, $uri, $body, $headers, $options);

        // Add the cookie of the calling user.
        if ($user = $this->getUser()) {
            $cookieName = $this->getConfig('Garden.Cookie.Name', 'Vanilla');
            $cookieArray = [$cookieName => $this->vanillaCookieString($user['UserID'])];

            $request->setHeader('Cookie', static::cookieEncode($cookieArray));

            if (!in_array($request->getMethod(), ['GET', 'OPTIONS'])) {
                $body = $request->getBody();
                if (is_array($body)) {
                    if (!isset($body['TransientKey'])) {
                        $body['TransientKey'] = $user['tk'];
                        $request->setBody($body);
                    }
                } elseif (is_string($body)) {
                    if (strpos($body, 'TransientKey') === false) {
                        if (!empty($body)) {
                            $body .= '&';
                        }
                        $body .= http_build_query(['TransientKey' => $user['tk']]);
                        $request->setBody($body);
                    }
                }
            }
        }

        return $request;
    }

    /**
     * Generate a Vanilla compatible cookie string for a user.
     *
     * @param int $userID The ID of the user.
     * @param string $secret The secret to secure the user. This is the cookie salt. If you pass an empty string then
     * the current configured salt will be used.
     * @param string $algo The algorithm used to sign the cookie.
     * @return string Returns a string that can be used as a Vanilla session cookie.
     */
    public function vanillaCookieString($userID, $secret = '', $algo = 'md5') {
        $expires = strtotime('+2 days');
        $keyData = "$userID-$expires";

        if (empty($secret)) {
            $secret = $this->getConfig('Garden.Cookie.Salt');
            if (empty($secret)) {
                // Throw a noisy exception because something is wrong.
                throw new \Exception("The cookie salt is empty.", 500);
            }
        }

        $keyHash = hash_hmac($algo, $keyData, $secret);
        $keyHashHash = hash_hmac($algo, $keyData, $keyHash);

        $cookieArray = [$keyData, $keyHashHash, time(), $userID, $expires];
        $cookieString = implode('|', $cookieArray);

        return $cookieString;
    }

    /**
     * Load the site's config and return it.
     *
     * This loads the config directly via filesystem access.
     *
     * @return array Returns the site's config.
     */
    public function loadConfigDirect() {
        $path = $this->getConfigPath();

        if (file_exists($path)) {
            $Configuration = [];
            require $path;
            return $Configuration;
        } else {
            return [];
        }
    }

    /**
     * Query the application's database.
     *
     * @param string $sql The SQL string to send.
     * @param array $params Any parameters to send with the SQL.
     * @param bool $returnStatement Whether or not to return the {@link \PDOStatement} associated with the query.
     * @return array|\PDOStatement
     */
    public function query($sql, array $params = [], $returnStatement = false) {
        $pdo = $this->getPDO();
        $stmt = $pdo->prepare($sql);

        $r = $stmt->execute($params);
        if ($r === false) {
            throw new \Exception($pdo->errorInfo(), $pdo->errorCode());
        }

        if ($returnStatement) {
            return $stmt;
        } else {
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $data;
        }
    }

    /**
     * Query the application's database and return the first row of the result.
     *
     * @param string $sql The SQL string to send.
     * @param array $params Any parameters to send with the SQL.
     * @return array|null Returns the first row of the query or **null** if there is no data.
     * @throws \Exception Throws an exception if there was a problem executing the query.
     */
    public function queryOne($sql, $params = []) {
        $data = $this->query($sql, $params);
        if (empty($data)) {
            return null;
        } else {
            return reset($data);
        }
    }

    /**
     * Save some config values via API.
     *
     * This method saves config values via a back-door endpoint copied to cgi-bin.
     * This is necessary because HHVM runs as root and takes over the config file and so it can only be edited in an
     * API context.
     *
     * @param array $values The values to save or the string `['DELETE']` to delete the config.
     */
    public function saveToConfig(array $values) {
        $r = $this->post(
            '/cgi-bin/saveconfig.php',
            $values,
            [
                'Content-Type: application/json;charset=utf-8',
                'Authorization: token '.self::getApiKey()
            ]
        );
        $this->config = $r->getBody();
        return $this->config;
    }

    /**
     * Save some config values.
     *
     * This saves the values directly via filesystem access.
     *
     * @param array $values An array of config keys and values where the keys are a dot-seperated array.
     */
    public function saveToConfigDirect(array $values) {
        $config = $this->loadConfigDirect();
        foreach ($values as $key => $value) {
            setvalr($key, $config, $value);
        }

        $path = $this->getConfigPath();

        $dir = dirname($path);

        $str = "<?php if (!defined('APPLICATION')) exit();\n\n".
            '$Configuration = '.var_export($config, true).";\n";
        $r = file_put_contents($path, $str);

        if ($r) {
            $this->config = $config;
        }
    }

    /**
     * Sign a user in to the application.
     *
     * @param string $username The username or email of the user.
     * @param string $password The password of the user.
     */
    public function signInUser($username, $password) {
        $r = $this->post(
            '/entry/password.json',
            ['Email' => $username, 'Password' => $password]
        );

        return $r;
    }


    /**
     * Uninstall Vanilla.
     *
     * @throws \Exception Throws an exception if the config file cannot be deleted.
     */
    public function uninstall() {
        $pdo = $this->getPDO();

        // Delete the config file.
        $this->saveToConfig(['DELETE']);

        // Delete the database.
        $dbname = $this->getDbName();
        $pdo->query("drop database if exists `$dbname`");
    }

    /**
     * Get the apiKey.
     *
     * @return mixed Returns the apiKey.
     */
    public static function getApiKey() {
        return self::$apiKey;
    }

    /**
     * Set the apiKey.
     *
     * @param mixed $apiKey
     * @return APIv0 Returns `$this` for fluent calls.
     */
    public static function setApiKey($apiKey) {
        self::$apiKey = $apiKey;
    }

    /**
     * Get the user to make API calls as.
     *
     * @return array Returns a user array.
     */
    public function getUser() {
        return $this->user;
    }

    /**
     * Query the system user.
     *
     * @param bool $throw Whether or not to throw an exception if the system user is not found.
     * @return array|null Returns the system user or **null** if they aren't found.
     * @throws \Exception Throws an exception if {@link $throw} is **true** and the system user isn't found.
     */
    public function querySystemUser($throw = false) {
        $user = $this->queryUser(['Admin' => 1], $throw);
        return $user;
    }

    /**
     * Query a user in the database.
     *
     * @param string|int $userKey The user ID or username of the user.
     * @param bool $throw Whether or not to throw an exception if the user isn't found.
     * @return array Returns the found user as an array.
     */
    public function queryUserKey($userKey, $throw = false) {
        if (is_numeric($userKey)) {
            $row = $this->queryUser(['UserID' => $userKey], $throw);
        } elseif (is_string($userKey)) {
            $row = $this->queryUser(['Name' => $userKey], $throw);
        }

        return $row;
    }

    /**
     * Query a use with a where array.
     *
     * @param array $where An array in the form **[field => value]**.
     * @param bool $throw Whether or not to throw an exception if the user isn't found.
     * @return array|null Returns the user array or null if no user is found.
     */
    public function queryUser($where, $throw = false) {
        // Build the where clause from the where array.
        $whereSql = [];
        $whereArgs = [];
        foreach ($where as $field => $value) {
            $whereSql[$field] = "$field = :$field";
            $whereArgs[':'.$field] = $value;
        }

        $sql = "select * from GDN_User where ".implode(' and ', $whereSql);
        $row = $this->queryOne($sql, $whereArgs);
        if (empty($row)) {
            if ($throw) {
                throw new \Exception("User not found.", 404);
            }
            return null;
        }

        $attributes = @unserialize($row['Attributes']);
        $row['Attributes'] = $attributes;
        $row['tk'] = val('TransientKey', $attributes);

        return $row;
    }

    /**
     * Set the user used to make API calls.
     *
     * @param array|string|int $user Either an array user, an integer user ID, a string username, or null to unset the
     * current user.
     * @return APIv0 Returns `$this` for fluent calls.
     */
    public function setUser($user) {
        if ($user === null) {
            $this->user = null;
            return $this;
        }

        if (is_scalar($user)) {
            $user = $this->queryUserKey($user, true);
        }

        if (empty($user['tk'])) {
            $user['tk'] = $this->getTK($user['UserID']);
        }

        $partialUser = ['UserID' => $user['UserID'], 'Name' => $user['Name'], 'tk' => $user['tk']];

        $this->user = $partialUser;
        return $this;
    }

    public function createDatabase() {
        // Create the database for Vanilla.
        $pdo = $this->getPDO();
        $dbname = $this->getDbName();
        $pdo->query("create database `$dbname`");
        $pdo->query("use `$dbname`");
    }
}
