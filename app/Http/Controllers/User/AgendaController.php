<?php

namespace App\Http\Controllers\User;

use App\Events\NewAppointmentWasBooked;
use App\Events\NewSoftAppointmentWasBooked;
use App\Http\Controllers\Controller;
use App\Services\ContactService;
use Carbon;
use Event;
use Illuminate\Http\Request;
use JavaScript;
use Notifynder;
use Timegridio\Concierge\Concierge;
use Timegridio\Concierge\Exceptions\DuplicatedAppointmentException;
use Timegridio\Concierge\Models\Appointment;
use Timegridio\Concierge\Models\Business;
use Timegridio\Concierge\Models\Contact;

class AgendaController extends Controller
{
    /**
     * Concierge service implementation.
     *
     * @var Timegridio\Concierge\Concierge
     */
    private $concierge;

    /**
     * Create Controller.
     *
     * @param Timegridio\Concierge\Concierge
     */
    public function __construct(Concierge $concierge)
    {
        $this->concierge = $concierge;

        parent::__construct();
    }

    /**
     * List all pending appointments.
     *
     * @return Response Rendered list view for User Appointments
     */
    public function getIndex()
    {
        logger()->info(__METHOD__);

        $appointments = auth()->user()->appointments()->orderBy('start_at')->unarchived()->get();

        return view('user.appointments.index', compact('appointments'));
    }

    /**
     * get Availability for Business.
     *
     * @param Business $business Business to query
     *
     * @return Response Rendered view of Appointment booking form
     */
    public function getAvailability(Business $business, Request $request)
    {
        logger()->info(__METHOD__);

        if (auth()->user()) {
            if (!auth()->user()->getContactSubscribedTo($business)) {
                logger()->info('  [ADVICE] User not subscribed to Business');

                flash()->warning(trans('user.booking.msg.you_are_not_subscribed_to_business'));

                return redirect()->route('user.businesses.home', compact('business'));
            }

            Notifynder::category('user.checkingVacancies')
               ->from('App\Models\User', auth()->id())
               ->to('Timegridio\Concierge\Models\Business', $business->id)
               ->url('http://localhost')
               ->send();

            if ($behalofOfId = $request->input('behalfOfId')) {
                $this->authorize('manageContacts', $business);

                $contact = $business->contacts()->find($behalofOfId);
            } else {
                $contact = auth()->user()->getContactSubscribedTo($business->id);
            }
        }

        $date = $request->input('date', 'today');
        $days = $request->input('days', $business->pref('availability_future_days'));

        $startFromDate = $this->sanitizeDate($date);

        if ($startFromDate->isPast()) {
            $startFromDate = $this->sanitizeDate('today');
        }

        $includeToday = $business->pref('appointment_take_today');

        if ($startFromDate->isToday() && !$includeToday) {
            $startFromDate = $this->sanitizeDate('tomorrow');
        }

        $availability = $this->concierge
                             ->business($business)
                             ->vacancies()
                             ->generateAvailability($business->vacancies, $startFromDate->toDateString(), $days);

        JavaScript::put([
            'language'  => 'en', // ToDo: Should load selected language or fallback
            'startDate' => $startFromDate->toDateString(),
            'endDate'   => $startFromDate->addDays($days)->toDateString(),
        ]);

        return view(
            'user.appointments.'.$business->strategy.'.book',
            compact('business', 'availability', 'startFromDate', 'contact')
        );
    }

    /**
     * post Store.
     *
     * @param Request $request Input data of booking form
     *
     * @return Response Redirect to Appointments listing
     */
    public function postStore(Request $request)
    {
        logger()->info(__METHOD__);

        //////////////////
        // FOR REFACTOR //
        //////////////////

        $business = Business::findOrFail($request->input('businessId'));
        $isOwner = false;

        if ($issuer = auth()->user()) {
            $isOwner = $issuer->isOwner($business->id);
            $contact = $this->findSubscrbedContact($issuer, $isOwner, $business, $request->input('contact_id'));
        } else {
            $contactService = new ContactService();
            $contact = $contactService->getExisting($business, $request->input('email'));

            if (!$contact) {
                logger()->info('[ADVICE] Not subscribed');

                flash()->warning(trans('user.booking.msg.store.not-registered'));

                return redirect()->back();
            }

            auth()->once(['email' => $request->input('email')]);
        }

        // Authorize contact is subscribed to Business
        // ...

        $service = $business->services()->find($request->input('service_id'));

        $date = Carbon::parse($request->input('_date'))->toDateString();
        $time = Carbon::parse($request->input('_time'))->toTimeString();
        $timezone = $request->input('_timezone') ?: $business->timezone;

        $comments = $request->input('comments');

        $reservation = [
            'issuer'   => auth()->id(),
            'contact'  => $contact,
            'service'  => $service,
            'date'     => $date,
            'time'     => $time,
            'timezone' => $timezone,
            'comments' => $comments,
        ];

        logger()->info('Reservation:'.print_r($reservation, true));

        try {
            $appointment = $this->concierge->business($business)->takeReservation($reservation);
        } catch (DuplicatedAppointmentException $e) {
            $code = $this->concierge->appointment()->code;

            logger()->info("DUPLICATED Appointment with CODE:{$code}");

            flash()->warning(trans('user.booking.msg.store.sorry_duplicated', compact('code')));

            if ($isOwner) {
                return redirect()->route('manager.business.agenda.index', compact('business'));
            }

            return redirect()->route('user.agenda');
        }

        if (false === $appointment) {
            logger()->info('[ADVICE] Unable to book');

            flash()->warning(trans('user.booking.msg.store.error'));

            return redirect()->back();
        }

        logger()->info('Appointment saved successfully');

        flash()->success(trans('user.booking.msg.store.success', ['code' => $appointment->code]));

        if (!$issuer) {
            event(new NewSoftAppointmentWasBooked($appointment));

            return view('guest.appointment.show', compact('appointment'));
        }

        event(new NewAppointmentWasBooked($issuer, $appointment));

        if ($isOwner) {
            return redirect()->route('manager.business.agenda.index', compact('business'));
        }

        return redirect()->route('user.agenda', '#'.$appointment->code);
    }

    public function getValidate(Request $request, Business $business)
    {
        $code = $request->input('code');
        $email = $request->input('email');

        if (strlen($code) < 4) {
            flash()->error(trans('user.booking.msg.validate.error.bad-code'));

            return redirect()->to('/');
        }

        // Get the Appointment starting with provided Hash and having Contact
        // with the provided email.

        $appointment = $business->bookings()
                                ->with('contact')
                                ->where('hash', 'like', "{$code}%")
                                ->whereHas('Contact', function ($q) use ($email) {
                                    $q->where('email', $email);
                                })->first();

        if (!$appointment) {
            flash()->error(trans('user.booking.msg.validate.error.no-appointment-was-found'));

            return redirect()->to('/');
        }

        if ($appointment->status == Appointment::STATUS_CONFIRMED) {
            flash()->error(trans('user.booking.msg.validate.error.your-appointment-is-already-confirmed'));

            return view('guest.appointment.show', compact('appointment'));
        }

        $appointment->doConfirm();

        flash()->success(trans('user.booking.msg.validate.success.your-appointment-was-confirmed'));

        return view('guest.appointment.show', compact('appointment'));
    }

    protected function findSubscrbedContact($issuer, $isOwner, Business $business, $contactId)
    {
        if ($contactId && $isOwner) {
            return $business->contacts()->find($contactId);
        }

        return $issuer->getContactSubscribedTo($business->id);
    }

    /////////////
    // HELPERS //
    /////////////

    /**
     * Sanitize Date String.
     *
     * @param string $dateString
     *
     * @return Carbon\Carbon
     */
    protected function sanitizeDate($dateString)
    {
        try {
            $date = Carbon::parse($dateString);
        } catch (\Exception $e) {
            logger()->warning('Unexpected date string: '.$dateString);
            $date = Carbon::now();
        }

        return $date;
    }
}
