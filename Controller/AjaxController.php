<?php

namespace SmartInformationSystems\AjaxBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
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
    protected $translationDomain = 'messages';

    /**
     * Обработчик аннотаций.
     *
     * @var AnnotationReader
     */
    private $annotationReader = NULL;

    /**
     * Запрос.
     *
     * @var Request
     */
    private $request;

    /**
     * Конструктор.
     *
     */
    public function __construct()
    {
        $this->annotationReader = new AnnotationReader();
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
     * Возвращает ajax-ответ при логической ошибке.
     *
     * @param \Exception $e
     * @param array $data Дополнительные данные
     *
     * @return JsonResponse
     */
    protected function ajaxErrorResponse(\Exception $e, $data = array())
    {
        return $this->ajaxResponse(FALSE, '', $e->getMessage(), $data);
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
            $this->annotationExists($method, 'SmartInformationSystems\AjaxBundle\Annotations\AjaxAction')
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
     * @param Request $request
     *
     * @return void
     */
    protected function preExecute(Request $request)
    {
        if ($this->isAjaxAction($this->getActionName($request)) && (!$request || !$request->isXmlHttpRequest())) {
            throw $this->createNotFoundException('Only ajax request available for this action.');
        }
    }

    /**
     * Возвращает название текущего Action.
     *
     * @param Request $request
     *
     * @return string
     */
    protected function getActionName(Request $request)
    {
        $arr = explode('::', $request->attributes->get('_controller'));
        return preg_replace('/Action$/', '', end($arr));
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
