<?php


namespace Singo\Tests\Commands;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class LoginCommand
 * @package Singo\Tests\Commands
 */
class LoginCommand
{
    /**
     * @Assert\Length(min = 3)
     * @Assert\NotBlank
     * @var string
     */
    private $username;

    /**
     * @Assert\Length(min = 3)
     * @Assert\NotBlank
     * @var string
     */
    private $password;

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @param string $username
     */
    public function setUsername($username)
    {
        $this->username = $username;
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @param string $password
     */
    public function setPassword($password)
    {
        $this->password = $password;
    }
}
