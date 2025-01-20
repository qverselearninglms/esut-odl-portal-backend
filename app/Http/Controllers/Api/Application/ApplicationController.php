<?php

namespace App\Http\Controllers\Api\Application;

use Carbon\Carbon;
use GuzzleHttp\Client;
use App\Mail\WelcomeMail;
use App\Models\PaymentLog;
use Illuminate\Http\Request;
use App\Models\ApplicationForm;
use App\Models\ApplicationPayment;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class ApplicationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api')->except(['initializePayment', 'verifyPayment', 'login', 'uploadPassport','uploadFirstSittingResult','uploadSecondSittingResult']);
    }
    public function initializePayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => ['required'],
            'last_name' => ['required'],
            'other_name' => ['nullable'],
            'faculty_id' => ['required', 'integer'],
            'department_id' => ['required', 'integer'],
            'nationality' => ['required'],
            'state' => ['required'],
            'phone_number' => ['required'],
            'email' => ['required', 'email'],
            // 'password' => [
            //     'required',
            //     'confirmed',
            //     'min:8',
            //     'regex:/[a-z]/',
            //     'regex:/[A-Z]/',
            //     'regex:/[0-9]/',
            //     'regex:/[@$!%*#?&]/',
            // ],
            'amount' => ['required'],
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 422, 'response' => 'Unprocessable Content', 'errors' => $validator->errors()], 422);
        }

        $callback_url = 'https://odl-esut.qverselearning.org/admission/payments/verify-admission';
        $client = new Client();
        $response = $client->post('https://api.credodemo.com/transaction/initialize', [
            'headers' => [
                'Authorization' => "0PUB0558ZeLIbGv0i0IRRUzvERhR4Hni",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'json' => [
                "customerFirstName" => $request->first_name,
                "customerLastName" => $request->last_name,
                "customerPhoneNumber" => $request->phone_number,
                "email" => $request->email,
                "amount" => $request->amount * 100,
                "callback_url" => $callback_url,
                "metadata" => [
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'other_name' => $request->other_name,
                    'faculty_id' => $request->faculty_id,
                    'department_id' => $request->department_id,
                    'nationality' => $request->nationality,
                    'state' => $request->state,
                    'phone_number' => $request->phone_number,
                    'email' => $request->email,
                    'password' => $request->password,
                    'amount' => $request->amount,
                ]
            ],
        ]);
        $data = json_decode($response->getBody());

        $application = new ApplicationPayment();
        $application->first_name = $request->first_name;
        $application->last_name = $request->last_name;
        $application->other_name = $request->other_name;
        $application->email = $request->email;
        $application->faculty_id = $request->faculty_id;
        $application->phone_number = $request->phone_number;
        $application->department_id = $request->department_id;
        $application->nationality = $request->nationality;
        $application->state = $request->state;
        $application->password = bcrypt($request->password);
        $application->amount = $request->amount;
        $application->reference = $data->data->credoReference;
        $application->save();
        if ($application->save()) {
            // Redirect to Paystack payment page
            // return response()->json($data->data->authorization_url);
            return response()->json($data);
        } else {
            return response()->json([
                'status' => 'Failed',
                'Message' => 'Server Error'
            ], 500);
        }
    }
    
    
    public function verifyPayment()
    {
        $curl = curl_init();
        $reference = isset($_GET['transRef']) ? $_GET['transRef'] : '';
        if(!$reference){
            return response()->json([
                'status' => 'Request Failed',
                'message' => 'No Reference Provided'
            ],422);
        }else{
            curl_setopt_array($curl, array(
              CURLOPT_URL => "https://api.credodemo.com/transaction/".$reference."/verify",
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: 0PRI0558gYl7120yXtnI978CuwZYbDox",
                "cache-control: no-cache"
              ],
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);

            if($err){
                // there was an error contacting the Paystack API
              die('Curl returned error: ' . $err);
            }

            $callback = json_decode($response);

            if(!$callback->status){
                // there was an error from the API
                die('API returned error: ' . $callback->message);
            }
            $status = $callback->status;
            $email = $callback->data->customerId;

            $detail = ApplicationPayment::where(['reference' => $reference, 'email' => $email])->first();
            if(!$detail){
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Transaction Details Not Found'
                ],404);
            }
            $DBreference = $detail->reference;

            if ($DBreference == $reference && $status == 200) {
                $updateDetail = ApplicationPayment::where(['reference' => $reference, 'email' => $email])->update(['application_payment_status'=>true]);
                PaymentLog::create([
                    'user_id' => $detail->id,
                    'payment_type' => 'Application Fee',
                    'amount' => $this->getInsightTagValue('amount',$callback->data->metadata),
                    'reference' => $reference,
                    'status' => 'Paid'
                ]);
                $url = "https://google.com";
                $password = $this->getInsightTagValue('password',$callback->data->metadata);
                Mail::to($detail->email)->send(new WelcomeMail($detail, $password, $url));
                return response()->json([
                    'status' => 'Successful',
                    'message' => 'Payment was successful'
                ],200);
            }else {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Failed to confirm Payment'
                ],401);
            }
        }
    }
    public function login(Request $request)
    {
        $credentials = $request->only('reference', 'password');

        if (!$token = Auth::guard('api')->attempt($credentials)) {
            return response()->json(['status' => 403, 'response' => 'Unauthorized', 'message' => 'Unauthorized User'], 403);
        }
        $user = ApplicationPayment::where('reference', $request->reference)->first();
        $payment_status = $user->application_payment_status;
        if($payment_status == false){
            return response()->json(['status' => 403, 'response' => 'Invalid Reference', 'message' => 'Unauthorized User'], 403);
        }

        return $this->createToken($token);
    }
    public function createToken($token)
    {
        $user = ApplicationPayment::find(auth('api')->user()->id);
        return response()->json([
            'status' => 200,
            'response' => 'Successful',
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 10080,
            'user' => $user
        ]);
    }
    public function applicationForm(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gender' => ['required'],
            'lga' => ['required'],
            'hometown' => ['required'],
            'hometown_address' => ['required'],
            'contact_address' => ['required'],
            'religion' => ['required'],
            'disability' => ['required'],
            'dob' => ['required'],
            'other_disability' => ['nullable'],
            'sponsor_name' => ['required'],
            'sponsor_relationship' => ['required'],
            'sponsor_phone_number' => ['required'],
            'sponsor_email' => ['required'],
            'sponsor_contact_address' => ['required'],
            'awaiting_result' => ['boolean'],
            'first_sitting' => ['nullable'],
            'second_sitting' => ['nullable'],
            'image_url' => ['required']
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 422, 'response' => 'Unprocessable Content', 'errors' => $validator->errors()], 422);
        }

        // Convert array to JSON string
        $firstSitting = json_encode($request->first_sitting);
        $secondSitting = $request->second_sitting ? json_encode($request->second_sitting) : null;
        try {
            $application_form = ApplicationForm::create([
                'user_id' => auth('api')->user()->id,
                'gender' => $request->gender,
                'lga' => $request->lga,
                'hometown' => $request->hometown,
                'hometown_address' => $request->hometown_address,
                'contact_address' => $request->contact_address,
                'religion' => $request->religion,
                'disability' => $request->disability,
                'dob' => $request->dob,
                'other_disability' => $request->other_disability,
                'sponsor_name' => $request->sponsor_name,
                'sponsor_relationship' => $request->sponsor_relationship,
                'sponsor_phone_number' => $request->sponsor_phone_number,
                'sponsor_email' => $request->sponsor_email,
                'sponsor_contact_address' => $request->sponsor_contact_address,
                'awaiting_result' => $request->awaiting_result,
                'first_sitting' => $firstSitting,
                'second_sitting' => $secondSitting,
                'passport' => $request->image_url
            ]);
            if($application_form){
                ApplicationPayment::find(auth('api')->user()->id)->update(['is_applied'=>true]);
                return response()->json(['status' => 200, 'response' => 'Successful', 'message' => 'Application Form Submitted Successfully.', 'data' => $application_form]);
            }
        } catch (\Exception $e) {
            // Consider logging the error here for debugging
            Log::error('Error uploading application form: ' . $e->getMessage());
            return response()->json(['status' => 500, 'response' => 'Server Error', 'message' => 'Error Uploading Application Form.'], 500);
        }

        
    }
    public function uploadPassport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'passport' => ['required', 'file'] // ensure these MIME types cover your needs
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'response' => 'Unprocessable Entity', 'errors' => $validator->errors()], 422);
        }

        if ($request->hasFile('passport')) {
            $passportNameToStore = $this->uploadFile($request->file('passport'), 'LMS/passport');
            if ($passportNameToStore) {
                return response()->json(['status' => 200, 'response' => 'Successful', 'message' => 'Image Uploaded.', 'image_url' => $passportNameToStore]);
            } else {
                return response()->json(['status' => 500, 'response' => 'Server Error', 'message' => 'Failed to upload passport.'], 500);
            }
        }

        // Handle case where no file is provided
        return response()->json(['status' => 400, 'response' => 'Bad Request', 'message' => 'No passport file provided.'], 400);
    }
    public function uploadFirstSittingResult(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_sitting_result' => ['required', 'file'] // ensure these MIME types cover your needs
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'response' => 'Unprocessable Entity', 'errors' => $validator->errors()], 422);
        }

        if ($request->hasFile('first_sitting_result')) {
            $firstResultNameToStore = $this->uploadFile($request->file('first_sitting_result'), 'LMS/first_sitting_result');
            if ($firstResultNameToStore) {
                return response()->json(['status' => 200, 'response' => 'Successful', 'message' => 'Image Uploaded.', 'image_url' => $firstResultNameToStore]);
            } else {
                return response()->json(['status' => 500, 'response' => 'Server Error', 'message' => 'Failed to upload firstResult.'], 500);
            }
        }

        // Handle case where no file is provided
        return response()->json(['status' => 400, 'response' => 'Bad Request', 'message' => 'No passport file provided.'], 400);
    }
    
    public function uploadSecondSittingResult(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'second_sitting_result' => ['required', 'file'] // ensure these MIME types cover your needs
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'response' => 'Unprocessable Entity', 'errors' => $validator->errors()], 422);
        }

        if ($request->hasFile('second_sitting_result')) {
            $secondResultNameToStore = $this->uploadFile($request->file('second_sitting_result'), 'LMS/second_sitting_result');
            if ($secondResultNameToStore) {
                return response()->json(['status' => 200, 'response' => 'Successful', 'message' => 'Image Uploaded.', 'image_url' => $secondResultNameToStore]);
            } else {
                return response()->json(['status' => 500, 'response' => 'Server Error', 'message' => 'Failed to upload secondResult.'], 500);
            }
        }

        // Handle case where no file is provided
        return response()->json(['status' => 400, 'response' => 'Bad Request', 'message' => 'No passport file provided.'], 400);
    }
    
    
    private function uploadFile($file, $folder)
    {
        try {
            $uploadedFile = cloudinary()->upload($file->getRealPath(), [
                'folder' => $folder
            ]);
            return $uploadedFile->getSecurePath();
        } catch (\Exception $e) {
            // Consider logging the error here for debugging
            Log::error('Error uploading file to Cloudinary: ' . $e->getMessage());
            return null;
        }
    }
    public function initializeAcceptancePayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required'],
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 422, 'response' => 'Unprocessable Content', 'errors' => $validator->errors()], 422);
        }

        $user = ApplicationPayment::find(auth('api')->user()->id);
        if($user->admission_status != 'admitted'){
            return response()->json(['status' => 422, 'response' => 'Unprocessable Content', 'message' => 'User has not been admiited'], 422);
        }

        $callback_url = 'https://odl-esut.qverselearning.org/admission/payments/verify-acceptance';
        $client = new Client();
        $response = $client->post('https://api.credodemo.com/transaction/initialize', [
            'headers' => [
                'Authorization' => "0PUB0558ZeLIbGv0i0IRRUzvERhR4Hni",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'json' => [
                "customerFirstName" => $user->first_name,
                "customerLastName" => $user->last_name,
                "customerPhoneNumber" => $user->phone_number,
                "email" => $user->email,
                "amount" => $request->amount * 100,
                "callback_url" => $callback_url,
                "metadata" => [
                    'user_id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'payment_type'=>'Acceptance Fee Payment',
                    'amount' => $request->amount,
                ]
            ],
        ]);
        $data = json_decode($response->getBody());
        return response()->json($data);
    }
    public function verifyAcceptancePayment()
    {
        $curl = curl_init();
        $reference = isset($_GET['transRef']) ? $_GET['transRef'] : '';
        if(!$reference){
            return response()->json([
                'status' => 'Request Failed',
                'message' => 'No Reference Provided'
            ],422);
        }else{
            curl_setopt_array($curl, array(
              CURLOPT_URL => "https://api.credodemo.com/transaction/".$reference."/verify",
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: 0PRI0558gYl7120yXtnI978CuwZYbDox",
                "cache-control: no-cache"
              ],
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);

            if($err){
                // there was an error contacting the Paystack API
              die('Curl returned error: ' . $err);
            }

            $callback = json_decode($response);

            if(!$callback->status){
                // there was an error from the API
                die('API returned error: ' . $callback->message);
            }
            $status = $callback->status;
            $email = $callback->data->customerId;

            if ($status == 200) {
                $updateDetail = ApplicationPayment::where(['email' => $email])->update(['accpetance_fee_payment_status'=>true]);
                PaymentLog::create([
                    'user_id' => auth('api')->user()->id,
                    'payment_type' => $this->getInsightTagValue('payment_type',$callback->data->metadata),
                    'amount' => $this->getInsightTagValue('amount',$callback->data->metadata),
                    'reference' => $reference,
                    'status' => 'Paid'
                ]);
                return response()->json([
                    'status' => 'Successful',
                    'message' => 'Acceptance Fee Payment was successful'
                ],200);
            }else {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Failed to confirm Payment'
                ],401);
            }
        }
    }
    public function initializeTuitionPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required'],
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 422, 'response' => 'Unprocessable Content', 'errors' => $validator->errors()], 422);
        }

        $user = ApplicationPayment::find(auth('api')->user()->id);
        if($user->accpetance_fee_payment_status != true){
            return response()->json(['status' => 422, 'response' => 'Unprocessable Content', 'message' => 'User needs to pay acceptance fee first'], 422);
        }

        $callback_url = 'https://odl-esut.qverselearning.org/admission/payments/verify-tuition';
        $client = new Client();
        $response = $client->post('https://api.credodemo.com/transaction/initialize', [
            'headers' => [
                'Authorization' => "0PUB0558ZeLIbGv0i0IRRUzvERhR4Hni",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'json' => [
                "customerFirstName" => $user->first_name,
                "customerLastName" => $user->last_name,
                "customerPhoneNumber" => $user->phone_number,
                "email" => $user->email,
                "amount" => $request->amount * 100,
                "callback_url" => $callback_url,
                "metadata" => [
                    'user_id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'payment_type'=>'Tuition Fee Payment',
                    'amount' => $request->amount,
                ]
            ],
        ]);
        $data = json_decode($response->getBody());

        // Redirect to Paystack payment page
        // return response()->json($data->data->authorization_url);
        return response()->json($data);
    }

    public function profile(Request $request)
    {
        $user = ApplicationPayment::where('id', auth()->user()->id)->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
        return response()->json(['status' => 200,'response' => $user->last_name.' '.$user->first_name.` fetched successfully`,'user' => $user], 200);
    }

    public function applicationData(Request $request)
    {
        // Fetch all payments with their associated application forms
        $application = ApplicationPayment::where(['id' => auth()->user()->id])->with('application')->first();

        // Check if the collection is empty
        if (!$application) {
            return response()->json([
                'status' => 404,
                'response' => 'Not Found',
                'message' => 'No Application(s) found'
            ], 404);
        }

        // Return a successful response with the data
        return response()->json([
            'status' => 200,
            'response' => 'Successful',
            "message" => "Application Detail fetched successfully",
            "data" => $application
        ], 200);
    }
    public function verifyTuitionPayment()
    {
        $curl = curl_init();
        $reference = isset($_GET['transRef']) ? $_GET['transRef'] : '';
        if(!$reference){
            return response()->json([
                'status' => 'Request Failed',
                'message' => 'No Reference Provided'
            ],422);
        }else{
            curl_setopt_array($curl, array(
              CURLOPT_URL => "https://api.credodemo.com/transaction/".$reference."/verify",
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "authorization: 0PRI0558gYl7120yXtnI978CuwZYbDox",
                "cache-control: no-cache"
              ],
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);

            if($err){
                // there was an error contacting the Paystack API
              die('Curl returned error: ' . $err);
            }

            $callback = json_decode($response);

            if(!$callback->status){
                // there was an error from the API
                die('API returned error: ' . $callback->message);
            }
            $status = $callback->status;
            $email = $callback->data->customerId;

            if ($status == 200) {
                /*$reg_number = Carbon::now()->format('Y').'345'.rand(1, 999);
                $updateDetail = ApplicationPayment::where(['email' => $email])->update(['tuition_payment_status'=>true,'reg_number' => $reg_number]);*/
                
                try {
                    do {
                        $reg_number = Carbon::now()->format('Y').'345'.rand(1, 999);
                        // Attempt to update
                        $updateDetail = ApplicationPayment::where(['reference' => auth('api')->user()->reference])
                            ->update(['tuition_payment_status' => true, 'reg_number' => $reg_number]);
                    } while (!$updateDetail); // Repeat if update fails
                } catch (QueryException $e) {
                    if ($e->errorInfo[1] == 1062) { // Handle duplicate entry error
                        return response()->json([
                            'status' => 'Failed',
                            'message' => 'Duplicate registration number, please try again'
                        ], 409);
                    }
                    return response()->json([
                        'status' => 'Failed',
                        'message' => 'Database error'
                    ], 500);
                }

                PaymentLog::create([
                    'user_id' => auth('api')->user()->id,
                    'payment_type' => $this->getInsightTagValue('payment_type',$callback->data->metadata),
                    'amount' => $this->getInsightTagValue('amount',$callback->data->metadata),
                    'reference' => $reference,
                    'status' => 'Paid'
                ]);
                return response()->json([
                    'status' => 'Successful',
                    'message' => 'Tuition Fee Payment was successful'
                ],200);
            }else {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Failed to confirm Payment'
                ],401);
            }
        }
    }

    private function getInsightTagValue($tag, $data)
    {
        // Loop through the data array
        for ($i = 0; $i < count($data); $i++) {
            // Check if the current item's insightTag matches the given tag
            if ($data[$i]->insightTag === $tag) {
                // Return the insightTagValue if a match is found
                return $data[$i]->insightTagValue;
            }
        }

        // Return null if no match is found
        return null;
    }
}
