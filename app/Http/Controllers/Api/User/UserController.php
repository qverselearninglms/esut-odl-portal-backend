<?php

namespace App\Http\Controllers\Api\User;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\ApplicationPayment;
use App\Models\ApplicationForm;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api',['except' => ['viewStudents','createStudent','viewSingleStudent']]);
    }

    public function createStudent(Request $request)
    {
        $this->validate($request,[
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required',
            'phone' => 'required',
            'login' => 'required',
            'reg_number' => 'required',
            'level' => 'required',
            /*'password' => [
                'required',
                'confirmed',
                'min:8',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*#?&]/',
            ],*/
        ]);

        $exist_reg = ApplicationPayment::where(['reg_number'=> $request->reg_number])->exists();
        if(!$exist_reg){
            return response()->json(['status' => 404, 'response' => 'Not Found', 'message' => 'Registration/Matric Number does not exist'], 404);
        }
        $user = new User;
        $user->first_name = $request->input('first_name');
        $user->last_name = $request->input('last_name');
        $user->login = $request->input('login');
        $user->email = $request->input('email');
        $user->phone = $request->input('phone');
        $user->reg_number = $request->input('reg_number');
        $user->level = $request->level;
        $user->is_enroll = 0;
        $user->password = bcrypt('P@55word');
        $user->save();
        
        $apply = ApplicationPayment::where(['reg_number' => $request->input('reg_number')])->first();
        $application = ApplicationForm::where(['user_id' => $apply->id])->first();
        
    
        // Save the user to the second database
        DB::connection('mysql2')->table('mdl_user')->insert([
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => 1,
            'username' => $request->input('reg_number'),
            'password' => '$6$rounds=10000$wUSMMdSKTp.HTT2h$f1gxqp3Zcvw4TatcfZ5ISqygbbTJpelNr7iQ1Pqdr5dxP0MsFgJg4MmGXuMPUltiWrKw7cwnTYlbjRgVDdKP30',
            'idnumber' => $request->input('reg_number'),
            'firstname' => $request->input('first_name'),
            'lastname' => $request->input('last_name'),
            'email' => $request->input('email'),
            'phone1' => $request->input('phone'),
            'phone2' => $request->input('phone'),
            'institution' => 'ESUT',
            //'department' => $apply->department,
            'department' => 'Industrial Mathematics And Statistics',
            'address' => $application->contact_address,
            'city' => $apply->state,
            'country' => 'NG',
        ]);

        return response()->json([
            'message' => 'Registration successful',
            'user' => $user
        ], 201);
    }

    public function viewStudents()
    {
        $users = User::all();
        if ($users->count() > 0) {
            return response()->json(['users'=>$users],200);
        } else {
            return response()->json(['message' => 'No user(s) found'], 404);
        }
    }

    public function viewSingleStudent(Request $request)
    {
        $this->validate($request,[
            'reg_number' => 'required',
        ]);

        $user = User::where('reg_number',  $request->input('reg_number'))
                    ->exists();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }else{
            $fetched_user = User::where('reg_number',  $request->input('reg_number'))
                    ->first();
            return response()->json($fetched_user,200);
        }
    }
}
