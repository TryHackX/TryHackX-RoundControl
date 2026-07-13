<?php
class custom_auth extends rcube_plugin
{
    public function init()
    {
        $this->add_hook('startup', array($this, 'startup_handler'));
        $this->add_hook('authenticate', array($this, 'authenticate_handler'));
        $this->add_hook('logout_after', array($this, 'logout_after_handler')); // <-- NOWE
    }

    public function startup_handler($args)
    {
        $rcmail = rcmail::get_instance();

        // Jeśli użytkownik jest zalogowany, pozwól mu kontynuować
        if ($rcmail->user && $rcmail->user->ID) {
            return $args;
        }

        // Każdy niezalogowany -> portal
        header('Location: /portal.php');
        exit;
    }

    public function logout_after_handler($args)
    {
        $rcmail = rcmail::get_instance();

        $rcmail->output->redirect('/portal.php');
    }

    public function authenticate_handler($args)
    {
        return $args;
    }
}
