<?php

namespace SmartSystems\AjaxBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\Common\Annotations\AnnotationReader;

/**
 * Обработчик ajax-запросов.
 *
 */
class AjaxController extends Controller
{
    /**
     * Домен переводов (файл с переводами).
     *
     * @var string
     */
    private $translationDomain = 'messages';

    /**
     * Обработчик аннотаций.
     *
     * @var AnnotationReader
     */
    private $annotationReader = NULL;

    /**
     * Отправитель писем.
     *
     * @var array
     */
    private $emailFrom = array('info@example.com', 'From example');

    /**
     * Конструктор.
     *
     */
    public function __construct()
    {
        $this->annotationReader = new AnnotationReader();
    }

    /**
     * Устанавливает отправителя писем.
     *
     * @param string $email Адрес отправителя
     * @param string $name Имя отправителя
     *
     * @return AjaxController
     */
    public function setEmailFrom($email, $name)
    {
        $this->emailFrom = array($email, $name);

        return $this;
    }

    /**
     * Возвращает обработчик аннотаций.
     *
     * @return AnnotationReader
     */
    public function getAnnotationReader()
    {
        return $this->annotationReader;
    }

    /**
     * Возвращает стандартный ajax-ответ.
     *
     * @param bool $success Запрос выполнен успешно
     * @param string $successText Сообщение об успехе
     * @param string $errorText Сообщение об ошибке
     * @param array $data Дополнительные данные
     *
     * @return JsonResponse
     */
    protected function ajaxResponse($success, $successText = '', $errorText = '', $data = array())
    {
        $json = $this->getJsonPrototype();

        if ($successText) {
            if (($translated = $this->translate($successText)) == $successText) {
                $this->get('logger')->emergency(
                    sprintf('Отсутствует перевод: "%s"', $successText)
                );
                $successText = '';
            } else {
                $successText = $translated;
            }
        }

        if (!$success && !$errorText) {
            $errorText = 'internal_error';
        }

        if ($errorText) {
            if (($translated = $this->translate($errorText)) == $errorText) {
                $this->get('logger')->emergency(
                    sprintf('Отсутствует перевод: "%s"', $errorText)
                );
                $errorText = '';
            } else {
                $errorText = $translated;
            }
        }

        $json['success'] = $success;
        $json['successText'] = $successText;
        $json['errorText'] = $errorText;

        $json = array_merge($json, $data);

        return new JsonResponse($json);
    }

    /**
     * Возвращает ajax-ответ при исключенении.
     *
     * @param \Exception $e
     *
     * @return JsonResponse
     */
    protected function ajaxExceptionResponse(\Exception $e)
    {
        $this->get('logger')->error($e->getMessage());

        return $this->ajaxResponse(FALSE, '', 'internal_error');
    }

    /**
     * Возвращает прототип json-ответа.
     *
     *
     * @return array
     */
    protected function getJsonPrototype()
    {
        return array(
            'success' => FALSE,
            'successText' => '',
            'errorText' => '',
        );
    }

    /**
     * Возвращает перевод строки.
     *
     * @param string $msg Строка
     *
     * @return string
     */
    protected function translate($msg)
    {
        static $translator;

        if (empty($translator)) {
            $translator = $this->get('translator');
        }

        return $translator->trans(
            $msg,
            $this->getGeneralTranslationParameters(),
            $this->getTranslationDomain()
        );
    }

    /**
     * Возвращает стандартные переменные для подстановки в перевод.
     *
     * @return array
     */
    private function getGeneralTranslationParameters()
    {
        return array(
        );
    }

    /**
     * Возвращает домен переводов (файл с переводами).
     *
     * @param string $domain Домен переводов
     *
     * @return string
     */
    public function setTranslationDomain($domain)
    {
        return $this->translationDomain = $domain;
    }

    /**
     * Возвращает домен переводов (файл с переводами).
     *
     * @return string
     */
    private function getTranslationDomain()
    {
        return $this->translationDomain;
    }

    /**
     * Проверяет доступен ли экшн для ajax-запроса.
     *
     * @param string $action Экшн
     *
     * @return bool
     */
    protected function isAjaxAction($action)
    {
        $method = $action . 'Action';

        return
            method_exists($this, $method)
            &&
            $this->annotationExists($method, 'SmartSystems\AjaxBundle\Annotations\AjaxAction')
        ;
    }

    /**
     * Проверяет существование аннотации в комментарии к методу.
     *
     * @param string $method Имя метода
     * @param string $annotation Аннотация
     *
     * @return bool
     */
    protected function annotationExists($method, $annotation)
    {
        return $this->getAnnotationReader()->getMethodAnnotation(
            new \ReflectionMethod(get_class($this), $method),
            $annotation
        );
    }

    /**
     * Вызывается перед каждым действием.
     *
     */
    protected function preExecute()
    {
        if ($this->isAjaxAction($this->getActionName()) && !$this->getRequest()->isXmlHttpRequest()) {
            throw $this->createNotFoundException('Only ajax request available for this action.');
        }
    }

    /**
     * Возвращает название текущего Action.
     *
     * @return string
     */
    protected function getActionName()
    {
        $arr = explode('::', $this->getRequest()->attributes->get('_controller'));
        return preg_replace('/Action$/', '', end($arr));
    }

    /**
     * {@inheritdoc)
     */
    public function setContainer(ContainerInterface $container = null)
    {
        parent::setContainer($container);

        $this->preExecute();
    }

    /**
     * Отправка письма.
     *
     * @param string $email Кому
     * @param string $template Шаблон
     * @param array $templateVars Переменные шаблона
     *
     * @return int
     */
    protected function sendEmail($email, $template, array $templateVars = array())
    {
        $templating = $this->get('templating');

        $message = \Swift_Message::newInstance()
            ->setSubject(
                $templating->render(
                    $template . '/subject.text.twig',
                    $templateVars
                )
            )
            ->setFrom($this->emailFrom[0], $this->emailFrom[1])
            ->setTo($email);

        $templateVars = array_merge(
            $templateVars,
            array(
                '_subject' => $message->getSubject(),
            )
        );

        $message->setBody(
            $templating->render(
                $template . '/body.html.twig',
                $templateVars
            ),
            'text/html',
            'utf8'
        );

        return $this->get('mailer')->send($message);
    }

    /**
     * Возвращает ajax-ответ при редиректе.
     *
     * @param string $routeName Имя маршрута
     * @param string $url Адрес для редиректа
     *
     * @return JsonResponse
     */
    protected function ajaxRedirectResponse($routeName, $url = '')
    {
        return new JsonResponse(array(
            'redirect' => $url ? $url : $this->get('router')->generate($routeName),
        ));
    }

    /**
     * Возвращает путь до страницы авторизации.
     *
     * @return string
     */
    protected function getAuthorizationUrl()
    {
        return '/';
    }

    /**
     * Возвращает ajax-ответ редиректа на авторизацию.
     *
     * @return JsonResponse
     */
    protected function ajaxNoAuthResponse()
    {
        return $this->ajaxRedirectResponse('', $this->getAuthorizationUrl());
    }
}
