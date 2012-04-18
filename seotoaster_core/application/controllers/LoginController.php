<?php

class LoginController extends Zend_Controller_Action {

    public function init() {
		$this->view->websiteUrl = $this->_helper->website->getUrl();
    }

    public function indexAction() {
		$this->_helper->page->doCanonicalRedirect('go');
		//if logged in user trys to go to the login page - redirect him to the main page
		if(Tools_Security_Acl::isAllowed(Tools_Security_Acl::RESOURCE_PAGE_PROTECTED)) {
			$this->_redirect($this->_helper->website->getUrl());
		}

        $loginForm = new Application_Form_Login();
		if($this->getRequest()->isPost()) {
			if($loginForm->isValid($this->getRequest()->getParams())) {
				$authAdapter = new Zend_Auth_Adapter_DbTable(
					Zend_Registry::get('dbAdapter'),
					'user',
					'email',
					'password',
					'MD5(?)'
				);
				$authAdapter->setIdentity($loginForm->getValue('email'));
				$authAdapter->setCredential($loginForm->getValue('password'));
				$authResult = $authAdapter->authenticate();
				if($authResult->isValid()) {
					$authUserData = $authAdapter->getResultRowObject(null, 'password');
					if(null !== $authUserData) {
						$user = new Application_Model_Models_User();
						$user->setId($authUserData->id);
						$user->setEmail($authUserData->email);
						//$user->setPassword($authUserData->password);
						$user->setRoleId($authUserData->role_id);
						$user->setFullName($authUserData->full_name);
						$user->setLastLogin(date('Y-m-d H:i:s', time()));
						$user->setRegDate($authUserData->reg_date);
						$user->setIpaddress($_SERVER['REMOTE_ADDR']);
						$this->_helper->session->setCurrentUser($user);

						Application_Model_Mappers_UserMapper::getInstance()->save($user);

						unset($user);
						$this->_helper->cache->clean();

						if($authUserData->role_id == Tools_Security_Acl::ROLE_MEMBER) {
							$this->_memberRedirect();
						}

						if(isset($this->_helper->session->redirectUserTo)) {
							$this->_redirect($this->_helper->website->getUrl() . $this->_helper->session->redirectUserTo, array('exit' => true));
						}
						$this->_redirect((isset($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : $this->_helper->website->getUrl());
					}
				}

				$signInType = $this->getRequest()->getParam('singintype');
				if($signInType && $signInType == Tools_Security_Acl::ROLE_MEMBER) {
					$this->_memberRedirect(false);
				}

				$this->_checkRedirect(false, 'There is no user with such login and password.');
			}
			else {
				$this->_checkRedirect(false, 'Login should be a valid email address');
			}
		}
		else {
			//getting available system translations
            $this->view->languages = $this->_helper->language->getLanguages();

			//getting messages
			$this->view->messages   = $this->_helper->flashMessenger->getMessages();

			//unset url redirect set from any login widget
			unset($this->_helper->session->redirectUserTo);

			$this->view->loginForm  = $loginForm;
		}
	}

	public function logoutAction() {
		$this->_helper->getHelper('layout')->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$this->_helper->session->getSession()->unsetAll();
		$this->_helper->cache->clean();
		$this->_checkRedirect($this->_helper->website->getUrl(), '');

	}

	private function _memberRedirect($success = true) {
		$landingPage = ($success) ? Tools_Page_Tools::getLandingPage(Application_Model_Models_Page::OPT_MEMLAND) : Tools_Page_Tools::getLandingPage(Application_Model_Models_Page::OPT_ERRLAND);
		if($landingPage instanceof Application_Model_Models_Page) {
			$this->_redirect($this->_helper->website->getUrl() . $landingPage->getUrl(), array('exit' => true));
		}
	}

	private function _checkRedirect($url = '', $message = '') {
		if($message) {
			$this->_helper->flashMessenger->addMessage($message);
		}
		if(isset($_SERVER['HTTP_REFERER'])) {
			$this->_helper->session->errMemeberLogin = $this->_helper->flashMessenger->getMessages();
			$this->_helper->redirector->gotoUrl($_SERVER['HTTP_REFERER'], array('exit' => true));
		}
		if(!$url) {
			$this->_helper->redirector->gotoRoute(array(
				'controller' => 'login',
				'action'     =>'index'
			));
		}
	}

	public function passwordretrieveAction() {
		$form = new Application_Form_PasswordRetrieve();
		if($this->getRequest()->isPost()) {
			if($form->isValid($this->getRequest()->getParams())) {
				$retrieveData = $form->getValues();
				$user = Application_Model_Mappers_UserMapper::getInstance()->findByEmail(filter_var($retrieveData['email'], FILTER_SANITIZE_EMAIL));
				//create new reset token and send e-mail to the user
				$resetToken = new Application_Model_Models_PasswordRecoveryToken(array(
					'saltString' => $retrieveData['email'],
					'expiredAt'  => date(DATE_ATOM, strtotime('+1 day', time())),
					'userId'     => $user->getId()
				));
				$resetTokenId = Application_Model_Mappers_PasswordRecoveryMapper::getInstance()->save($resetToken);
				if($resetTokenId) {
					$this->_helper->flashMessenger->addMessage('We\'ve sent an email to ' . $user->getEmail() . ' containing a temporary url that will allow you to reset your password for the next 24 hours. Please check your spam folder if the email doesn\'t appear within a few minutes.');

				   	//temporary mail sending
					$resetUrl = $this->_helper->website->getUrl() . 'login/reset/email/' . $user->getEmail() . '/token/' . $resetToken->getTokenHash();
					$mailer   = new Tools_Mail_Mailer();
					$mailer->setMailFrom('support@seotoaster.com');
					$mailer->setMailFromLabel('Seotoaster support team');
					$mailer->setMailTo($user->getEmail());
					$mailer->setBody('<a href="' . $resetUrl . '">' . $resetUrl . '</a>');
					$mailer->setSubject('[Seotoaster] Please reset your password');
					$mailer->send();

					$this->_helper->redirector->gotoRoute(array(
						'controller' => 'login',
						'action'     => 'passwordretrieve'
					));
				}
			} else {
				$messages       = array_values($form->getMessages());
				$flashMessanger = $this->_helper->flashMessenger;
				foreach($messages as $messageData) {
					if(is_array($messageData)) {
						array_walk($messageData, function($msg) use($flashMessanger) {
							$flashMessanger->addMessage($msg);
						});
					} else {
						$flashMessanger->addMessage($messageData);
					}
				}
				return $this->_redirect($this->_helper->website->getUrl() . 'login/retrieve/');
			}
		}
		$this->view->messages = $this->_helper->flashMessenger->getMessages();
		$this->view->form     = $form;
	}

	public function passwordresetAction() {
		//cehck the get string for the tokens http://mytoaster.com/login/reset/email/myemail@mytoaster.com/token/adadajqwek123klajdlkasdlkq2e3
		$error = false;
		$form  = new Application_Form_PasswordReset();
		$email = filter_var($this->getRequest()->getParam('email', false), FILTER_SANITIZE_EMAIL);
		$token = filter_var($this->getRequest()->getParam('token', false), FILTER_SANITIZE_STRING);

		if(!$email || !$token) {
			$error = true;
		}
		$resetToken = Application_Model_Mappers_PasswordRecoveryMapper::getInstance()->findByTokenAndMail($token, $email);
		if(!$resetToken
			|| $resetToken->getStatus() != Application_Model_Models_PasswordRecoveryToken::STATUS_NEW
			|| $this->_isTokenExpired($resetToken)) {
				$error = true;
		}
		if($error) {
			$error = false;
			$this->_helper->flashMessenger->addMessage('Token is incorrect. Please, enter your e-mail one more time.');
			return $this->_redirect($this->_helper->website->getUrl() . 'login/retrieve/');
		}

		if($this->getRequest()->isPost()) {
			if($form->isValid($this->getRequest()->getParams())) {
				$resetData = $form->getValues();
				$mapper    = Application_Model_Mappers_UserMapper::getInstance();
				$user      = $mapper->find($resetToken->getUserId());
				$user->setPassword($resetData['password']);
				$mapper->save($user);
				$resetToken->setStatus(Application_Model_Models_PasswordRecoveryToken::STATUS_USED);
				Application_Model_Mappers_PasswordRecoveryMapper::getInstance()->save($resetToken);
			} else {
				$this->_helper->flashMessenger->addMessage('Passwords');
			}
		}
		$this->view->form = $form;
	}

	/**
	 * Check if the token is expired. If so change status and return true.
	 *
	 * @param Application_Model_Models_PasswordRecoveryToken $token
	 * @return bool
	 */
	private function _isTokenExpired(Application_Model_Models_PasswordRecoveryToken $token) {
		if(strtotime($token->getExpiredAt()) < time()) {
			$token->setStatus(Application_Model_Models_PasswordRecoveryToken::STATUS_EXPIRED);
			Application_Model_Mappers_PasswordRecoveryMapper::getInstance()->save($token);
			return true;
		}
		return false;
	}
}

