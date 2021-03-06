<?php defined('SYSPATH') or die('No direct access allowed.');

/**
 * Template Controller Core Auth
 *
 * @package Auth
 * @author Sergei Gladkovskiy <smgladkovskiy@gmail.com>
 */
abstract class Controller_Core_Auth extends Controller_Template {

	protected $_email         = NULL;
	protected $_config        = NULL;
	protected $_auth_required = FALSE;
	protected $_referrer      = NULL;

	public function before()
	{
		parent::before();

		$this->_email  = Kohana::$config->load('email');
		$this->_config = Kohana::$config->load('user_auth');
		$this->_referrer = Session::instance()->get('url', Route::url('default', array('lang' => I18n::$lang)));
	}

	/**
	 * Old path redirect
	 *
	 * @return void
	 */
	public function action_user()
	{
		HTTP::redirect(Route::url('user', array('action' => 'cabinet', 'lang' => I18n::lang())));
	}

	/**
	 * Login routine
	 *
	 * @return void
	 */
	public function action_login()
	{
		$registration = $this->_config->open_registration;
		if(Auth::instance()->logged_in())
			HTTP::redirect();

		$post = array(
			'email'    => NULL,
			'password' => NULL,
			'remember' => FALSE
		);
		$errors = NULL;

		if($this->request->method() === HTTP_Request::POST)
		{
			$post_data = Arr::extract($this->request->post(), array_keys($post));

			if(Auth::instance()->login(
				$post_data['email'],
				$post_data['password'],
				(bool) $post_data['remember']))
			{
				Session::instance()->delete('url');
				HTTP::redirect($this->_referrer);
			}
			else
			{
				$errors = 'Неверное имя пользователя или пароль';
			}

			$post = Arr::merge($post, $post_data);
		}

		StaticJs::instance()->add_modpath('js/auth.js');
		$this->template->modals .= View::factory('frontend/modal/auth/remember');
		$this->template->modals .= View::factory('frontend/modal/auth/passEmailSend');
		// это может быть редирект с другой страницы - надо залогиниться
		$reason = Session::instance()->get_once('login_reason');

		$this->template->title      = __('Авторизация');
		$this->template->content    = View::factory('frontend/form/auth/login')
			->bind('registration', $registration)
			->bind('post', $post)
			->bind('reason', $reason)
			->bind('errors', $errors)
			->set('can_remember', $this->_config->remember_functional)
		;
	}

	/**
	 * Вход с использованием авторизационного хэша
	 *
	 * @throws HTTP_Exception_404
	 * @return void
	 */
	public function action_hash_login()
	{
		$hash = HTML::chars($this->request->param('hash'));

		if( ! $hash)
			throw new HTTP_Exception_404();

		$hash = Jelly::query('hash')
			->select_column('object_id', 'id')
			->where('hash', '=', $hash)
			->where('date_valid_end', '>=', time())
			->limit(1);

		// выбор пользователя по хэшу
		$user = Jelly::query('user', $hash)->select();

		if( ! $user->loaded())
		{
			HTTP::redirect(
				Route::url('auth', array(
					'lang' => I18n::$lang,
					'action' => 'message',
					'hash' => 'hash_expired',
				))
			);
		}
		else
		{
			// Форсированный вход
			Auth::instance()->force_login($user, TRUE);

			// удаление хэша, дабы устранить повторное использование
			$hash->delete();

			// редирект на страницу смены пароля
			HTTP::redirect(
				Route::url(
					'user',
					array(
						'lang' => I18n::$lang,
						'action' => 'change_pass',
					)
				)
			);
		}
	}

	/**
	 * User Logout
	 *
	 * @return void
	 */
	public function action_logout()
	{
		Auth::instance()->logout();
		Session::instance()->delete('url');
		HTTP::redirect($this->_referrer);
	}

	/**
	 * User registration
	 *
	 * @throws HTTP_Exception_404
	 * @return void
	 */
	public function action_registration()
	{
		$registration = $this->_config->open_registration;

		if( ! $registration)
			$this->request->redirect('');

		if(Auth::instance()->logged_in())
			$this->request->redirect(Route::url('user', array('action' => 'cabinet', 'lang' => I18n::lang())));

		$errors    = array();
		$post_user = array();
		$post_user_data = array();
		$post      = array(
			'id'        => NULL,
			'user'      => array(
				'email'     => NULL,
			),
			'user_data' => array(
				'last_name'  => NULL,
				'first_name' => NULL,
				'patronymic' => NULL,
			),
		);

		if($this->request->method() === HTTP_Request::POST)
		{
			$session_id = Session::instance()->id();
			$post_data = Arr::extract($this->request->post(), array_keys($post), NULL);
			$post_user = Arr::extract(Arr::get($post_data,'user'), array_keys($post['user']));
			$post_user_data = Arr::extract(Arr::get($post_data,'user_data'), array_keys($post['user_data']));

			if($post_data['id'])
			{
				$user = Jelly::query('user')->where('user_session', '=', md5($post_data['id']))->limit(1)->select();
			}
			else
			{
				$user = Jelly::factory('user');
			}

//			exit(Debug::vars($user) . View::factory('profiler/stats'));

			$user->set($post_user);
			$user->user_session = md5($session_id);
			try
			{
				$user->save();
			}
			catch(Jelly_Validation_Exception $e)
			{
				$errors['user'] = $e->errors('validate');
			}

			$post['id'] = $session_id;

		    if( ! $errors)
			{
				$user_data = Jelly::factory('user_data');
				$user_data->set($post_user_data);
				$user_data->user = $user;
				try
				{
					$user_data->save();
				}
				catch(Jelly_Validation_Exception $e)
				{
					$errors['user_data'] = $e->errors('validate');
				}
			}

			if( ! $errors)
			{
				$this->_send_confirmation($user);
			}
		}

		$post['user'] = Arr::merge($post['user'], $post_user);
		$post['user_data'] = Arr::merge($post['user_data'], $post_user_data);

		$this->page_title = __('Регистрация');
		$this->template->content = View::factory('frontend/form/auth/registration')
			->bind('post', $post)
			->bind('errors', $errors)
			;
	}

	/**
	 * Напоминание пароля
	 *
	 * @return void
	 */
	public function action_pass_remind()
	{
		$post = array('email' => NULL);

		if($this->request->method() === HTTP_Request::POST)
		{
			$post = Validation::factory(Arr::extract($this->request->post(), array('email')))
				->rule('email', 'not_empty')
				->rule('email', 'email')
				->label('email', __('Эл. адрес'))
			;

			if( ! $post->check())
			{
				$errors = $post->errors('validate');
			}
			else
			{
				$user = Jelly::query('user')
					->where('email', '=', HTML::chars($post['email']))
					->limit(1)
					->select();

				if($user->loaded())
				{
					$this->_send_new_password($user);
				}
				else
				{
					$errors[] = __('Вы не были зарегистрированы на нашем сайте. ') . HTML::anchor(
						Route::url('auth', array('action' => 'registration', 'lang' => I18n::lang())),
						__('Зарегистрируйтесь!'),
						array('class' => 'button')
					);
				}
			}
		}

		$this->template->title = __('Напоминание пароля');
		$this->template->content = View::factory('frontend/form/auth/password/remind')
			->bind('post', $post)
			->bind('errors', $errors)
		;
	}

	/**
	 * Отправка письма со ссылкой для смены пароля
	 *
	 * @throws HTTP_Exception_500
	 * @param Model_User $user
	 * @return void
	 */
	protected function _send_new_password(Model_User $user)
	{
		$hash = Jelly::factory('hash')
			->set(array(
				'object'         => 'user',
				'object_id'      => $user->id,
				'hash'           => md5(Text::random()),
				'date_valid_end' => time() + 3600*24,
			));

		try
		{
			$hash->save();

			// отправка пользователю письма с ссылкой для авторизации
			$message = View::factory('frontend/template/email')
				->set('content', View::factory('frontend/content/auth/mail/password/remind')
					->set('lang', $this->request->param('lang'))
					->set('hash', $hash->hash)
				);

			Email::connect();
			Email::send(
				$user->email,
				$this->_email->email_noreply,
				'Напоминание пароля',
				$message,
				TRUE
			);

			$this->request->redirect(
				Route::url('auth', array(
					'action' => 'message',
					'hash' => 'password_remind_send',
				))
			);
		}
		catch(Jelly_Validation_Exception $e)
		{
			throw new HTTP_Exception_500('Ошибка сохранения хэша в напоминании пароля');
		}
	}

	/**
	 * Отправка писем пользователю о успешном подтверждении регистрации и в CRM его данные
	 *
	 * @todo переделать email на xml-rpc запрос непосредственно в CRM
	 * @param Model_User $user
	 * @param string     $password
	 * @return void
	 */
	public function _send_registration_emails(Model_User $user, $password)
	{
		// Сообщение пользователю
		$message_user = View::factory('frontend/template/email')
			->set('content', View::factory('frontend/content/auth/mail/credentials')
				->bind('password', $password)
				->bind('user', $user)
			);

		Email::connect();
		Email::send(
			$user->email,
			$this->_email->email_noreply,
			'Регистрация прошла успешно',
			$message_user,
			TRUE
		);

	}

	/**
	 * Confirmation to password remind (change) request
	 *
	 * @return void
	 */
	public function action_confirmation()
	{
		$hash = Jelly::query('hash')
			->where('hash', '=', HTML::chars($this->request->param('hash')))
			->limit(1)
			->select();

	    if( ! $hash->loaded() OR $hash->date_valid_end < time())
	    {
			HTTP::redirect(
				Route::url('auth', array(
					'action' => 'message',
					'hash' => 'fail',
				))
			);
	    }

		$user = Jelly::factory('user')->set(array(
			'email' => $hash->object_id,
		));

		try
		{
			$user->save();
		}
		catch(Jelly_Validation_Exception $e)
		{
			exit(Debug::vars($e->errors('validate')));
		}


		$user->add('roles', Jelly::query('role')->where('name', '=', 'login')->limit(1)->execute());
		$user->add('roles', $hash->object_params['role']);
		$user->add('companies', $hash->object_params['companies']);
		$user->add('projects', $hash->object_params['projects']);
		$user->save();


		Auth::instance()->force_login($user, TRUE);

		$hash->delete();

		Session::instance()->set('registration', TRUE);

		// редирект на страницу смены пароля
		HTTP::redirect(
			Route::url(
				'user',
				array(
					'lang' => I18n::$lang,
					'action' => 'change_pass',
				)
			)
		);
	}

	public function action_update_hash()
	{
		$hash_sum = $this->request->param('hash');
		if($hash_sum != NULL)
		{
			$hash = Jelly::query('hash')
				->where('hash', '=', HTML::chars($hash_sum))
				->limit(1)
				->select()
			;

			if($hash->loaded())
			{
				if($hash->object != 'new_user' OR $hash->object == 'new_user' AND ! $hash->object_id)
					throw new HTTP_Exception_404();

				if($hash->object == 'new_user')
				{
					$user = Jelly::query('user')->where('email', '=', $hash->object_id)->limit(1)->select();
					if($user->loaded())
					{
						$hash->delete();
						throw new HTTP_Exception_404();
					}
				}

				$hash->set(array(
					'hash'      => md5(Text::random()),
					'date_valid_end' => time() + 3600*24,
				));

				try
				{
					$hash->save();
				}
				catch(Jelly_Validation_Exception $e)
				{
					throw new HTTP_Exception_500;
				}

				// отправка пользователю письма с ссылкой для подтверждения аккаунта
				$message = View::factory('frontend/content/auth/mail/confirm')
					->set('lang', $this->request->param('lang'))
					->set('hash', $hash->hash)
				;

				Email::factory(
					__('Обновление сессии подтверждения регистрации | :site_name', array(':site_name' => $this->_config->site->site_name)),
					$message,
					'text/html'
				)
					->to($hash->object_id)
					->from($this->_email->email_noreply)
					->bcc('smgladkovskiy@gmail.com')
					->send()
				;

				HTTP::redirect(
					Route::url('auth', array(
						'lang' => $this->request->param('lang'),
						'action' => 'message',
						'hash' => 'session_updated'
					))
				);
			}
			else
			{
				throw new HTTP_Exception_404();
			}
		}
	    else
	    {
		    throw new HTTP_Exception_404();
	    }
	}

	public function action_message()
	{
		$message = $this->request->param('hash');

		$this->page_title = __($message);
		$this->template->content = View::factory('frontend/content/auth/'.$message);
	}

} // End Controller_Core_Auth