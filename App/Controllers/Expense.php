<?php

namespace App\Controllers;

use \Core\View;
use \App\Auth;
use \App\Flash;
use \App\Models\Expenses;

/**
 * Profile controller
 */
class Expense extends Authenticated
{
    /**
     * Before filter - called before each action method
     */
    protected function before()
    {
        parent::before();

        $this->user = Auth::getUser();
    }

    /**
     * Sign up a new user
     *
     * @return void
     */
    public function createAction()
    {
        $expense = new Expenses($_POST);

        if ($expense->save()) {

            $this->redirect('/expense/success');
        } else {

            View::renderTemplate('Expense/add.html', [
                'expense' => $expense
            ]);
        }
    }

    /**
     * Show the profile
     */
    public function addAction()
    {
        View::renderTemplate('Expense/add.html', [
            'user' => $this->user
        ]);
    }

    public function successAction()
    {
        View::renderTemplate('Expense/success.html');
    }
    
    public function expensesAction()
    {
        $id = $this->route_params['id'];
        $date = $this->route_params['date'];

        echo json_encode(Expenses::getExpenseSumAssignedToUser($id, $date), JSON_UNESCAPED_UNICODE);
    }

    public function limitAction()
    {
        $id = $this->route_params['id'];

        echo json_encode(Expenses::getExpenseLimitAssignedToUser($id), JSON_UNESCAPED_UNICODE);
    }
}
