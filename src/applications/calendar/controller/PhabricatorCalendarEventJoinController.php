<?php

final class PhabricatorCalendarEventJoinController
  extends PhabricatorCalendarController {

  private $id;

  public function handleRequest(AphrontRequest $request) {
    $this->id = $request->getURIData('id');
    $request = $this->getRequest();
    $viewer = $request->getViewer();
    $declined_status = PhabricatorCalendarEventInvitee::STATUS_DECLINED;
    $attending_status = PhabricatorCalendarEventInvitee::STATUS_ATTENDING;

    $event = id(new PhabricatorCalendarEventQuery())
      ->setViewer($viewer)
      ->withIDs(array($this->id))
      ->executeOne();

    if (!$event) {
      return new Aphront404Response();
    }

    $cancel_uri = '/E'.$event->getID();
    $validation_exception = null;

    $is_attending = $event->getIsUserAttending($viewer->getPHID());

    if ($request->isFormPost()) {
      $new_status = null;

      if ($is_attending) {
        $new_status = array($viewer->getPHID() => $declined_status);
      } else {
        $new_status = array($viewer->getPHID() => $attending_status);
      }

      $xaction = id(new PhabricatorCalendarEventTransaction())
        ->setTransactionType(
          PhabricatorCalendarEventTransaction::TYPE_INVITE)
        ->setNewValue($new_status);

      $editor = id(new PhabricatorCalendarEventEditor())
        ->setActor($viewer)
        ->setContentSourceFromRequest($request)
        ->setContinueOnNoEffect(true)
        ->setContinueOnMissingFields(true);

      try {
        $editor->applyTransactions($event, array($xaction));
        return id(new AphrontRedirectResponse())->setURI($cancel_uri);
      } catch (PhabricatorApplicationTransactionValidationException $ex) {
        $validation_exception = $ex;
      }
    }

    if (!$is_attending) {
      $title = pht('Join Event');
      $paragraph = pht('Would you like to join this event?');
      $submit = pht('Join');
    } else {
      $title = pht('Decline Event');
      $paragraph = pht('Would you like to decline this event?');
      $submit = pht('Decline');
    }

    return $this->newDialog()
      ->setTitle($title)
      ->setValidationException($validation_exception)
      ->appendParagraph($paragraph)
      ->addCancelButton($cancel_uri)
      ->addSubmitButton($submit);
  }
}