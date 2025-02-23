<?php

namespace App\Models;

use PDO;
use \App\Token;
use \App\Mail;
use \Core\View;

/**
 * User model
 *
 * PHP version 7.0
 */
class User extends \Core\Model
{

    /**
     * Error messages
     *
     * @var array
     */
    public $errors = [];
    public $info = [];
    public $status = [];

    /**
     * Class constructor
     *
     * @param array $data  Initial property values
     *
     * @return void
     */
    public function __construct($data = [])
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        };
    }

    /**
     * Save the user model with the current property values
     *
     * @return boolean  True if the user was saved, false otherwise
     */
    public function save()
    {
        $this->validate();

        if (empty($this->errors)) {

            $password_hash = password_hash($this->password, PASSWORD_DEFAULT);

            $token = new Token();
            $hashed_token = $token->getHash();
            $this->activation_token = $token->getValue();

            $sql = 'INSERT INTO users (username, password, email, activation_hash)
                    VALUES (:name, :password, :email, :activation_hash)';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
            $stmt->bindValue(':password', $password_hash, PDO::PARAM_STR);
            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':activation_hash', $hashed_token, PDO::PARAM_STR);

            $stmt->execute();

            $this->loadDefaultTables($this->email);

            return true;

        }

        return false;
    }

    /**
     * Validate current property values, adding valiation error messages to the errors array property
     *
     * @return void
     */
    public function validate()
    {
        // Name
        if (isset($this->name)) {
            if ((strlen($this->name) < 3) || (strlen($this->name) > 20)) {
                $this->errors['name'] = 'Nazwa użytkownika musi posiadać od 3 do 20 znaków!';
            }
        }

        // email address
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors['email'] = 'Nieprawidłowy email!';
        }
        if (static::emailExists($this->email, $this->id ?? null)) {
            $this->errors['email'] = 'Email jest już zajęty!';
        }

        // Password
        if (isset($this->password)) {

            if ((strlen($this->password) < 6) || (strlen($this->password) > 20)) {
                $this->errors['password'] = 'Hasło musi zawierać od 4 do 20 znaków!';
            }

            if (preg_match('/.*[a-z]+.*/i', $this->password) == 0) {
                $this->errors['password'] = 'Hasło wymaga przynajmniej jednej litery!';
            }

            if (preg_match('/.*\d+.*/i', $this->password) == 0) {
                $this->errors['password'] = 'Hasło wymaga przynajmniej jednej cyfry!';
            }
        }

        // Repeat Password
        if (isset($this->repeatPassword)) {

            if ($this->password !== $this->repeatPassword) {
                $this->errors['repeatPassword'] = 'Hasła muszą być takie same!';
            }
        }
    }

    /**
     * Load default incomes and expenses tables
     */
    private function loadDefaultTables($userEmail)
    {
        
        $db = static::getDB();

        $idQuery = $db->query("SELECT id FROM users WHERE email='$userEmail'");
        $userId = $idQuery->fetchColumn();

        $expensesCategoryDefaultQuery = $db->query("SELECT name FROM expenses_category_default");
        $namesOfExpensesCategories = $expensesCategoryDefaultQuery->fetchAll();

        foreach ($namesOfExpensesCategories as $category) {
            $name = $category['name'];
            $insertExpenseCategories = $db->prepare("INSERT INTO expenses_category_assigned_to_users VALUES(NULL, '$userId', '$name', '0')");
            $insertExpenseCategories->execute();
        }

        $incomesCategoryDefaultQuery = $db->query("SELECT name FROM incomes_category_default");
        $namesOfIncomesCategories = $incomesCategoryDefaultQuery->fetchAll();

        foreach ($namesOfIncomesCategories as $category) {
            $name = $category['name'];
            $insertIncomesCategories = $db->prepare("INSERT INTO incomes_category_assigned_to_users VALUES(NULL, '$userId', '$name')");
            $insertIncomesCategories->execute();
        }

        $paymentMethodsDefaultQuery = $db->query("SELECT name FROM payment_methods_default");
        $namesOfPaymentMethods = $paymentMethodsDefaultQuery->fetchAll();

        foreach ($namesOfPaymentMethods as $category) {
            $name = $category['name'];
            $insertPaymentMethod = $db->prepare("INSERT INTO payment_methods_assigned_to_users VALUES(NULL, '$userId', '$name')");
            $insertPaymentMethod->execute();
        }
    }

    /**
     * See if a user record already exists with the specified email
     *
     * @param string $email email address to search for
     *
     * @return boolean  True if a record already exists with the specified email, false otherwise
     */
    public static function emailExists($email, $ignore_id = null)
    {
        $user = static::findByEmail($email);

        if ($user) {
            if ($user->id != $ignore_id) {
                return true;
            }
        }

        return false;
    }

    public static function findByEmail($email)
    {
        $sql = 'SELECT * FROM users WHERE email = :email';

        $db = static::getDB();
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);

        $stmt->setFetchMode(PDO::FETCH_CLASS, get_called_class());

        $stmt->execute();

        return $stmt->fetch();
    }

    public static function authehticate($email, $password)
    {
        $user = static::findByEmail($email);

        if ($user && $user->is_active) {
            if (password_verify($password, $user->password)) {
                return $user;
            }

            return false;
        }
    }

    public static function findByID($id)
    {
        $sql = 'SELECT * FROM users WHERE id = :id';

        $db = static::getDB();
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        $stmt->setFetchMode(PDO::FETCH_CLASS, get_called_class());

        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * Remember login by inserting new unique token into remembered_logins table
     */
    public function rememberLogin()
    {
        $token = new Token();
        $hashed_token = $token->getHash();
        $this->remember_token = $token->getValue();

        $this->expiry_timestamp = time() + 60 * 60 * 24 * 30; //30 days

        $sql = 'INSERT INTO remembered_logins (token_hash, user_id, expires_at)
        VALUES (:token_hash, :user_id, :expires_at)';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':token_hash', $hashed_token, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $this->id, PDO::PARAM_INT);
        $stmt->bindValue(':expires_at', date('Y-m-d H:i:s', $this->expiry_timestamp), PDO::PARAM_STR);

        return $stmt->execute();
    }

    /**
     * Send password reset instructions to the user specified
     */
    public static function sendPasswordReset($email)
    {
        $user = static::findByEmail($email);

        if ($user) {

            if ($user->startPasswordReset()) {

                $user->sendPasswordResetEmail();
            }
        } else {
            return false;
        }
    }

    /**
     * Start the password reset process by generating a new token and expiry
     */
    protected function startPasswordReset()
    {
        $token = new Token();
        $hashed_token = $token->getHash();
        $this->password_reset_token = $token->getValue();

        $expiry_timestamp = time() + 60 * 60 * 2; //2 hours from now

        $sql = 'UPDATE users
                SET password_reset_hash = :token_hash,
                password_reset_expires_at = :expires_at
                WHERE id = :id';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':token_hash', $hashed_token, PDO::PARAM_STR);
        $stmt->bindValue(':expires_at', date('Y-m-d H:i:s', $expiry_timestamp), PDO::PARAM_STR);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Send password reset instructions in an email
     */
    protected function sendPasswordResetEmail()
    {
        $url = 'https://' . $_SERVER['HTTP_HOST'] . '/password/reset/' . $this->password_reset_token;

        $text = View::getTemplate('Password/reset_email.txt', ['url' => $url]);
        $html = View::getTemplate('Password/reset_email.html', ['url' => $url]);

        Mail::send($this->email, 'Password reset', $text, $html);
    }

    /**
     * Find a user model by password reset token and expiry
     */
    public static function findByPasswordReset($token)
    {
        $token = new Token($token);
        $hashed_token = $token->getHash();

        $sql = 'SELECT * FROM users
                WHERE password_reset_hash = :token_hash';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':token_hash', $hashed_token, PDO::PARAM_STR);

        $stmt->setFetchMode(PDO::FETCH_CLASS, get_called_class());

        $stmt->execute();

        $user = $stmt->fetch();

        if ($user) {

            //Check password reset token date if it hasn't expired
            if (strtotime($user->password_reset_expires_at) > time()) {

                return $user;
            }
        }
    }

    /**
     * Reset the password
     */
    public function resetPassword($password)
    {
        $this->password = $password;

        $this->validate();

        if (empty($this->errors)) {

            $password_hash = password_hash($this->password, PASSWORD_DEFAULT);

            $sql = 'UPDATE users
                    SET password = :password,
                        password_reset_hash = NULL,
                        password_reset_expires_at = NULL
                    WHERE id = :id';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindValue(':password', $password_hash, PDO::PARAM_STR);

            return $stmt->execute();
        }

        return false;
    }

    /**
     * Send account activation email
     */
    public function sendActivationEmail()
    {
        $url = 'https://' . $_SERVER['HTTP_HOST'] . '/register/activate/' . $this->activation_token;

        $text = View::getTemplate('Register/activation_email.txt', ['url' => $url]);
        $html = View::getTemplate('Register/activation_email.html', ['url' => $url]);

        Mail::send($this->email, 'Account activation', $text, $html);
    }

    /**
     * Activate the user account with the specified activation token
     */
    public static function activate($value)
    {
        $token = new Token($value);
        $hashed_token = $token->getHash();

        $sql = 'UPDATE users
                SET is_active = 1,
                    activation_hash = null
                WHERE activation_hash = :hashed_token';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':hashed_token', $hashed_token, PDO::PARAM_STR);

        $stmt->execute();
    }

    /**
     * Update the user's profile
     */
    public function updateProfile($data)
    {
        $this->name = $data['name'];
        $this->email = $data['email'];

        //Only validate and update the password if a value was provided
        if ($data['password'] != '') {
            $this->password = $data['password'];
        }

        $this->validate();

        if (empty($this->errors)) {

            $sql = 'UPDATE users
                    SET username = :name,
                        email = :email';

            // Add password if it's set
            if (isset($this->password)) {
                $sql .= ', password = :password';
            }

            $sql .= "\nWHERE id = :id";

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);

            if (isset($this->password)) {
                $password_hash = password_hash($this->password, PASSWORD_DEFAULT);
                $stmt->bindValue(':password', $password_hash, PDO::PARAM_STR);
            }

            return $stmt->execute();
        }

        return false;
    }

    public function changeUsername($newUsername)
    {
        if(isset($this->info['name'])) unset($this->info['name']);
        $inputUsername = $newUsername;
        $newUsername = strtolower($newUsername);
        $previousName = strtolower($this->username);
        $userID = $this->id;

        if ((strlen($newUsername) < 3) || (strlen($newUsername) > 20)) {
            $this->info['name'] = 'Nazwa użytkownika musi posiadać od 3 do 20 znaków!';
        }

        if ($previousName == $newUsername) {
            $this->info['name'] = 'Nazwa użytkownika musi się różnić!';
        }

        if(!isset($this->info['name'])) {
            $this->status['name'] = true;

            $db = static::getDB();

            $sql = "UPDATE users
            SET username = :newUsername
            WHERE id = :userId";
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':userId', $userID, PDO::PARAM_INT);
            $stmt->bindValue(':newUsername',  $inputUsername, PDO::PARAM_STR);

            return $stmt->execute();
        }
    }

    public function changePassword()
    {
        if(isset($this->info['password'])) unset($this->info['password']);
        $userInputPassword = $_POST['newPassword'];
        $newPassword = $_POST['newPassword'];
        $newPassword = strtolower($newPassword);
        $repeatedPassword = $_POST['repeatNewPassword'];
        $repeatedPassword = strtolower($repeatedPassword);
        $userID = $this->id;

        if (isset($_POST['newPassword'])) {

            if ((strlen($newPassword) < 6) || (strlen($newPassword) > 20)) {
                $this->info['password'] = 'Hasło musi zawierać od 4 do 20 znaków!';
            }

            if (preg_match('/.*[a-z]+.*/i', $newPassword) == 0) {
                $this->info['password'] = 'Hasło wymaga przynajmniej jednej litery!';
            }

            if (preg_match('/.*\d+.*/i', $newPassword) == 0) {
                $this->info['password'] = 'Hasło wymaga przynajmniej jednej cyfry!';
            }
        }

        // Repeat Password
        if (isset($repeatedPassword)) {

            if ($newPassword !== $repeatedPassword) {
                $this->info['password'] = 'Hasła muszą być takie same!';
            }
        }

        if (password_verify( $userInputPassword, $this->password)) {
            $this->info['password'] = 'Hasło musi się różnic od poprzednio użytego!';
        }

        if(!isset($this->info['password'])) {
            $this->status['password'] = true;
            $password_hash = password_hash($userInputPassword, PASSWORD_DEFAULT);

            $db = static::getDB();

            $sql = "UPDATE users
            SET password = :newPassword
            WHERE id = :userId";
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':userId', $userID, PDO::PARAM_INT);
            $stmt->bindValue(':newPassword',  $password_hash, PDO::PARAM_STR);

            return $stmt->execute();
        }
    }
    
}
