<?php

/*
| Where "inside Dhaka" ends.
|
| The delivery fee (৳80 inside, ৳150 outside, free over the threshold only inside) treats Dhaka CITY as "inside": the
| division Dhaka, the district Dhaka, and a thana from the list below. Everything else (Gazipur, Narayanganj, Savar,
| Keraniganj, the rest of the Dhaka division...) is outside.
|
| The two lists are the only place the boundary lives. To move a thana, delete it from one list and add it to the other.
| Spell it exactly as the storefront sends it (src/lib/bangladesh-geography.ts); matching ignores case and extra spaces
| but never renames anything. A Dhaka-district thana in neither list is charged the outside fee and logged.
*/

return [

    'dhaka_city' => [
        'division' => 'Dhaka',
        'district' => 'Dhaka',

        // The 50 city / metropolitan thanas (DHAKA_METRO_THANAS in the storefront file).
        'thanas' => [
            'Adabor', 'Airport', 'Badda', 'Banani',
            'Bangshal', 'Bhashantek', 'Cantonment', 'Chackbazar',
            'Dakshin Khan', 'Darus-Salam', 'Demra', 'Dhanmondi',
            'Gandaria', 'Gulshan', 'Hatirjheel', 'Hazaribagh',
            'Jatrabari', 'Kadamtoli', 'Kafrul', 'Kalabagan',
            'Kamrangirchar', 'Khilgaon', 'Khilkhet', 'Kotwali',
            'Lalbagh', 'Mirpur', 'Mohammadpur', 'Motijheel',
            'Mugda', 'New Market', 'Pallabi', 'Paltan',
            'Ramna', 'Rampura', 'Rupnagar', 'Sabujbag',
            'Shah Ali', 'Shahbag', 'Shahjahanpur', 'Sher-e-Bangla Nagar',
            'Shyampur', 'Sutrapur', 'Tejgaon', 'Tejgaon Industrial Area',
            'Turag', 'Uttar Khan', 'Uttara East', 'Uttara West',
            'Vatara', 'Wari',
        ],
    ],

    // Upazilas of Dhaka district that are NOT the city: outside fee, no free delivery.
    'dhaka_district_outside' => [
        'Dhamrai', 'Dohar', 'Keraniganj', 'Nawabganj', 'Savar',
    ],

];
