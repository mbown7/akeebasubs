<?php
/**
 *  @package AkeebaSubs
 *  @copyright Copyright (c)2010-2011 Nicholas K. Dionysopoulos
 *  @license GNU General Public License version 3, or later
 */

defined('_JEXEC') or die();

FOFTemplateUtils::addCSS('media://com_akeebasubs/css/frontend.css?'.AKEEBASUBS_VERSIONHASH);

$this->loadHelper('cparams');
$this->loadHelper('modules');
$this->loadHelper('format');

?>

<div id="akeebasubs">

<table class="subscription-table">
	<tr>
		<td class="subscription-label"><?php echo JText::_('COM_AKEEBASUBS_COMMON_ID')?></td>
		<td class="subscription-info">
			<strong><?php echo sprintf('%05u', $this->item->akeebasubs_subscription_id)?></strong>
		</td>
	</tr>
	<tr>
		<td class="subscription-label"><?php echo JText::_('COM_AKEEBASUBS_SUBSCRIPTIONS_USER')?></td>
		<td class="subscription-info">
			<strong><?php echo JFactory::getUser($this->item->user_id)->username?></strong>
			(<em><?php echo JFactory::getUser($this->item->user_id)->name?></em>)
		</td>
	</tr>
	<tr>
		<td class="subscription-label"><?php echo JText::_('COM_AKEEBASUBS_SUBSCRIPTIONS_LEVEL')?></td>
		<td class="subscription-info">
			<?php echo FOFModel::getTmpInstance('Levels','AkeebasubsModel')->setId($this->item->akeebasubs_level_id)->getItem()->title?>
		</td>
	</tr>
	<tr>
		<td class="subscription-label"><?php echo JText::_('COM_AKEEBASUBS_SUBSCRIPTIONS_PUBLISH_UP')?></td>
		<td class="subscription-info">
			<?php echo AkeebasubsHelperFormat::date($this->item->publish_up, '%Y-%m-%d %H:%M') ?>
		</td>
	</tr>
	<tr>
		<td class="subscription-label"><?php echo JText::_('COM_AKEEBASUBS_SUBSCRIPTIONS_PUBLISH_DOWN')?></td>
		<td class="subscription-info">
			<?php echo AkeebasubsHelperFormat::date($this->item->publish_down, '%Y-%m-%d %H:%M') ?>
		</td>
	</tr>
	<tr>
		<td class="subscription-label"><?php echo JText::_('COM_AKEEBASUBS_SUBSCRIPTIONS_ENABLED')?></td>
		<td class="subscription-info">
			<?php if($this->item->enabled):?>
			<img src="<?php echo JURI::base(); ?>/media/com_akeebasubs/images/frontend/enabled.png" align="center" />
			<?php else:?>
			<img src="<?php echo JURI::base(); ?>/media/com_akeebasubs/images/frontend/disabled.png" align="center" />
			<?php endif;?>
		</td>
	</tr>
	<tr>
		<td class="subscription-label"><?php echo JText::_('COM_AKEEBASUBS_SUBSCRIPTIONS_STATE')?></td>
		<td class="subscription-info"><?php echo JText::_('COM_AKEEBASUBS_SUBSCRIPTION_STATE_'.$this->item->state)?></td>
	</tr>
	<tr>
		<td class="subscription-label"><?php echo JText::_('COM_AKEEBASUBS_SUBSCRIPTION_AMOUNT_PAID')?></td>
		<td class="subscription-info">
			<?php echo sprintf('%2.02f',$this->item->gross_amount)?>
			<?php echo AkeebasubsHelperCparams::getParam('currencysymbol','€')?>
		</td>
	</tr>
	<tr>
		<td class="subscription-label"><?php echo JText::_('COM_AKEEBASUBS_SUBSCRIPTION_SUBSCRIBED_ON')?></td>
		<td class="subscription-info">
			<?php echo AkeebasubsHelperFormat::date($this->item->created_on, '%Y-%m-%d %H:%M') ?>
		</td>
	</tr>
</table>
	
<div class="akeebasubs-goback">
	<p><a href="<?php echo JRoute::_('index.php?option=com_akeebasubs&view=subscriptions')?>"><?php echo JText::_('COM_AKEEBASUBS_SUBSCRIPTIONS_TITLE')?></a></p>
</div>

</div>