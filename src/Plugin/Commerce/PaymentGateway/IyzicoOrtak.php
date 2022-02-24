<?php


namespace Drupal\commerce_payment_iyzico\Plugin\Commerce\PaymentGateway;

use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment_iyzico\PluginForm\Offsite\IyzipayBootstrap;


/**
 * Provides the QuickPay offsite Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "iyzico_ortak",
 *   label = @Translation("Iyzico"),
 *   display_label = @Translation("Kredi/Banka Kartı"),
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_payment_iyzico\PluginForm\Offsite\IyzicoForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa",
 *   },
 * )
 */
class IyzicoOrtak extends OffsitePaymentGatewayBase
{
  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_key' => '',
      'security_key' => '',
      'installments' => '',
      'sandbox_url' => 'https://sandbox-api.iyzipay.com',
      'live_url' => 'https://api.iyzipay.com',
      'virtual_products' => '',
    ] + parent::defaultConfiguration();+ parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $this->configuration['api_key'],
      '#required' => TRUE,
    ];
    $form['security_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Security key'),
      '#default_value' => $this->configuration['security_key'],
      '#required' => TRUE,
    ];
    $form['installments'] = [
      '#type' => 'select',
      '#title' => $this
        ->t('Kullanılabilir taksitleri seçiniz'),
      '#options' => [
        '2' => '2 Taksit',
        '3' => '3 Taksit',
        '6' => '6 Taksit',
        '9' => '9 Taksit'
      ],
      '#multiple' => true,
      '#default_value' => $this->configuration['installments'],
    ];
    $product_types = \Drupal::entityTypeManager()->getStorage('commerce_product_type')->loadMultiple();
    $product_options = [];
    foreach($product_types as $key => $value){
    $product_options[$key] = $value->label();
    }
    $form['virtual_products'] = [
      '#type' => 'select',
      '#title' => $this
        ->t('Sanal ürün türlerini seçiniz'),
      '#options' => $product_options,
      '#multiple' => true,
      '#default_value' => $this->configuration['virtual_products'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['api_key'] = $values['api_key'];
    $this->configuration['security_key'] = $values['security_key'];
    $this->configuration['installments'] = $values['installments'];
    $this->configuration['virtual_products'] = $values['virtual_products'];
  }

  private function getOptions() {
    IyzipayBootstrap::init();

    $iyzicoOptions = new \Iyzipay\Options();
    $iyzicoOptions->setApiKey($this->getConfiguration()['api_key']);
    $iyzicoOptions->setSecretKey($this->getConfiguration()['security_key']);
    $iyzicoOptions->setBaseUrl($this->getConfiguration()['mode'] == 'live' ? $this->getConfiguration()['live_url'] : $this->getConfiguration()['sandbox_url']);

    return $iyzicoOptions;
  }

  public function onReturn(OrderInterface $order, Request $request) {
    IyzipayBootstrap::init();

    $iyzicoOptions = $this->getOptions();

    $iyzicoData = $order->getData('commerce_payment_iyzico');

    $iyzicoRequest = new \Iyzipay\Request\RetrieveCheckoutFormRequest();
    $iyzicoRequest->setLocale(\Iyzipay\Model\Locale::TR);
    $iyzicoRequest->setToken($iyzicoData['token']);

    $iyzicoResponse = \Iyzipay\Model\CheckoutForm::retrieve($iyzicoRequest, $iyzicoOptions);

    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => $iyzicoResponse->getPaymentStatus(),
      'payment_gateway' => $this->entityId,
      'amount' => $order->getTotalPrice(),
      'order_id' => $order->id(),
      'remote_id' => $iyzicoResponse->getPaymentId(),
      'remote_state' => $iyzicoResponse->getPaymentStatus(),
    ]);
    $payment->save();

    if ($iyzicoResponse->getStatus() !== 'success' || $iyzicoResponse->getPaymentStatus() !== 'SUCCESS') {
        drupal_set_message($iyzicoResponse->getErrorMessage(), "error");
        throw new DeclineException($iyzicoResponse->getErrorMessage());
    }

    $payment->set('amount', new Price(strval($iyzicoResponse->getPaidPrice()), $iyzicoResponse->getCurrency()));
    $payment->save();

    $vade_farki = $iyzicoResponse->getPaidPrice() - $order->getTotalPrice()->getNumber();

    if ($vade_farki) {
      $vade_farki_adjustment = new Adjustment([
           'type' => 'fee',
           'label' => 'Vade Farkı',
           'amount' => new Price(strval($vade_farki), $iyzicoResponse->getCurrency()),
           'percentage' => strval(($vade_farki*100)/$iyzicoResponse->getPaidPrice()),
         ]);
      $order->addAdjustment($vade_farki_adjustment);
    }

    //throw new DeclineException('durduruldu');

}




}
