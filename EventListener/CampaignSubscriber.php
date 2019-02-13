<?php

/*
 * @copyright   2019 Mautic, Inc. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\CustomObjectsBundle\EventListener;

use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\CustomObjectsBundle\CustomObjectsBundle;
use Mautic\LeadBundle\LeadEvents;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use Symfony\Component\Translation\TranslatorInterface;
use MauticPlugin\CustomObjectsBundle\Form\Type\CampaignActionLinkType;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Mautic\CoreBundle\Helper\ArrayHelper;
use MauticPlugin\CustomObjectsBundle\CustomItemEvents;
use MauticPlugin\CustomObjectsBundle\Form\Type\CampaignConditionFieldValueType;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldModel;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;

class CampaignSubscriber extends CommonSubscriber
{
    /**
     * @var CustomFieldModel
     */
    private $customFieldModel;

    /**
     * @var CustomObjectModel
     */
    private $customObjectModel;

    /**
     * @var CustomItemModel
     */
    private $customItemModel;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     *
     * @param CustomFieldModel $customFieldModel
     * @param CustomObjectModel $customObjectModel
     * @param CustomItemModel $customItemModel
     * @param TranslatorInterface $translator
     */
    public function __construct(
        CustomFieldModel $customFieldModel,
        CustomObjectModel $customObjectModel,
        CustomItemModel $customItemModel,
        TranslatorInterface $translator
    )
    {
        $this->customFieldModel  = $customFieldModel;
        $this->customObjectModel = $customObjectModel;
        $this->customItemModel   = $customItemModel;
        $this->translator        = $translator;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD               => ['onCampaignBuild'],
            CustomItemEvents::ON_CAMPAIGN_TRIGGER_ACTION    => ['onCampaignTriggerAction'],
            CustomItemEvents::ON_CAMPAIGN_TRIGGER_CONDITION => ['onCampaignTriggerCondition'],
        ];
    }

    /**
     * @todo don't forget to disable these methods when the plugin is disabled
     *
     * Add event triggers and actions.
     *
     * @param CampaignBuilderEvent $event
     */
    public function onCampaignBuild(CampaignBuilderEvent $event)
    {
        $customObjects = $this->customObjectModel->fetchAllPublishedEntities();

        foreach ($customObjects as $customObject) {
            $event->addAction("custom_item.{$customObject->getId()}.linkcontact", [
                'label'           => $this->translator->trans('custom.item.events.link.contact', ['%customObject%' => $customObject->getNameSingular()]),
                'description'     => $this->translator->trans('custom.item.events.link.contact_descr', ['%customObject%' => $customObject->getNameSingular()]),
                'eventName'       => CustomItemEvents::ON_CAMPAIGN_TRIGGER_ACTION,
                'formType'        => CampaignActionLinkType::class,
                'formTypeOptions' => ['customObjectId' => $customObject->getId()],
            ]);

            $event->addCondition("custom_item.{$customObject->getId()}.fieldvalue", [
                'label'           => $this->translator->trans('custom.item.events.field.value', ['%customObject%' => $customObject->getNameSingular()]),
                'description'     => $this->translator->trans('custom.item.events.field.value_descr', ['%customObject%' => $customObject->getNameSingular()]),
                'eventName'       => CustomItemEvents::ON_CAMPAIGN_TRIGGER_CONDITION,
                'formType'        => CampaignConditionFieldValueType::class,
                'formTheme'       => 'CustomObjectsBundle:FormTheme\FieldValueCondition',
                'formTypeOptions' => ['customObject' => $customObject],
            ]);
        }
    }

    /**
     * @param CampaignExecutionEvent $event
     */
    public function onCampaignTriggerAction(CampaignExecutionEvent $event)
    {
        if (!preg_match('/custom_item.(\d).linkcontact/', $event->getEvent()['type'])) {
            return;
        }

        $linkCustomItemId   = ArrayHelper::getValue('linkCustomItemId', $event->getConfig());
        $unlinkCustomItemId = ArrayHelper::getValue('unlinkCustomItemId', $event->getConfig());
        $contactId          = (int) $event->getLead()->getId();

        if ($linkCustomItemId) {
            $this->customItemModel->linkContact($linkCustomItemId, $contactId);
        }

        if ($unlinkCustomItemId ) {
            $this->customItemModel->unlinkContact($unlinkCustomItemId, $contactId);
        }
    }

    /**
     * @param CampaignExecutionEvent $event
     */
    public function onCampaignTriggerCondition(CampaignExecutionEvent $event)
    {
        if (!preg_match('/custom_item.(\d).fieldvalue/', $event->getEvent()['type'])) {
            return;
        }

        $contact = $event->getLead();

        if (!$contact || !$contact->getId()) {
            return $event->setResult(false);
        }

        try {
            $customField = $this->customFieldModel->fetchEntity($event->getConfig()['field']);
        } catch (NotFoundException $e) {
            return $event->setResult(false);
        }

        $expr = $customField->getTypeObject()->getOperators()[$event->getConfig()['operator']]['expr'];

        try {
            $customItemId = $this->customItemModel->findItemIdForValue(
                $customField,
                $contact,
                $expr,
                $event->getConfig()['value']
            );
            
            $event->setChannel('customItem', $customItemId);
    
            return $event->setResult(true);
        } catch (NotFoundException $e) {
            return $event->setResult(false);
        }
    }
}
