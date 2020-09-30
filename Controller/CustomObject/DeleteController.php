<?php

declare(strict_types=1);

/*
 * @copyright   2018 Mautic, Inc. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\CustomObjectsBundle\Controller\CustomObject;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Service\FlashBag;
use MauticPlugin\CustomObjectsBundle\CustomObjectEvents;
use MauticPlugin\CustomObjectsBundle\Event\CustomObjectEvent;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\SessionProviderFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class DeleteController extends CommonController
{
    /**
     * @var CustomObjectModel
     */
    private $customObjectModel;

    /**
     * @var SessionProviderFactory
     */
    private $sessionProviderFactory;

    /**
     * @var FlashBag
     */
    private $flashBag;

    /**
     * @var CustomObjectPermissionProvider
     */
    private $permissionProvider;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    public function __construct(
        CustomObjectModel $customObjectModel,
        SessionProviderFactory $sessionProviderFactory,
        FlashBag $flashBag,
        CustomObjectPermissionProvider $permissionProvider,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->customObjectModel      = $customObjectModel;
        $this->sessionProviderFactory = $sessionProviderFactory;
        $this->flashBag               = $flashBag;
        $this->permissionProvider     = $permissionProvider;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @return Response|JsonResponse
     */
    public function deleteAction(int $objectId)
    {
        try {
            $customObject = $this->customObjectModel->fetchEntity($objectId);
            $this->permissionProvider->canDelete($customObject);
        } catch (NotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (ForbiddenException $e) {
            return $this->accessDenied(false, $e->getMessage());
        }

        $customObjectEvent = new CustomObjectEvent($customObject);
        $this->eventDispatcher->dispatch(CustomObjectEvents::ON_CUSTOM_OBJECT_USER_PRE_DELETE, $customObjectEvent);

        $translationParameters = [
            '%name%' => $customObject->getName(),
            '%id%'   => $customObject->getId(),
        ];

        $message = $customObjectEvent->getMessage() ?: $this->translator->trans('mautic.core.notice.deleted', $translationParameters, 'flashes');

        $this->flashBag->add($message);

        return $this->forward(
            'CustomObjectsBundle:CustomObject\List:list',
            ['page' => $this->sessionProviderFactory->createObjectProvider()->getPage()]
        );
    }
}
