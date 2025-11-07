<?php

use Carbon\Carbon;
?>

<!DOCTYPE html>
<html>

<head>
    <title>Ticket Booking Confirmation</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Oxygen,
                Ubuntu, Cantarell, Fira Sans, Droid Sans, Helvetica Neue, sans-serif;
            max-width: 600px;
            margin: 0px auto;
            background-color: #eeeeee;
        }

        h4 {
            text-align: center;
            font-size: 30px;
        }

        h5 {
            text-align: center;
        }

        h3 {
            text-align: center;
        }

        p {
            text-align: center;
        }

        .logo-img {
            width: 120px;
            height: 50px;
            margin-left: 20px;
        }

        .col-50 {
            width: 50%;
        }

        .col-25 {
            width: 25%;
        }

        .col-12 {
            width: 100%;
        }

        .col-6 {
            width: 60%;
        }

        .col-5 {
            width: 50%;
        }

        .col-4 {
            width: 40%;
        }

        .col-3 {
            width: 30%;
        }

        .row {
            width: 100%;
            display: flex;
            flex-direction: row;
            text-align: center;
        }

        .row.direction-col {
            flex-direction: column;
        }

        .row.space-between {
            justify-content: space-between;
        }

        .p-20 {
            padding: 20px;
        }

        .p-10 {
            padding: 10px;
        }

        .qr-code {
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        .m-0 {
            margin: 0;
        }

        .mt-20 {
            margin-top: 20px;
        }

        .red {
            color: red;
        }

        blank-cell {

            min-width: 50px;


        }

        .attendance-cell {

            padding: 8px;


        }

        .attendance-table table th.attendance-cell,
        .attendance-table table td.attendance-cell {
            border: 1px solid #000;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row p-10">
            <div class="col-3">
                <img class="logo-img" src="https://spotseeker.s3.ap-south-1.amazonaws.com/public/logo-trans.png" alt="logo" />
            </div>

        </div>
        <div class="row">
            <div class="col-12">
                <h5 class="m-0">BOOKING ID</h5>
                <h4>{{$data['order_id']}}</h4>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <h3>{{$data['event_name']}}</h3>
                <h5 class="m-0">{{$data['event_venue']}}</h5>
                <h5 class="m-0">
                    {{Carbon::parse($data['event_date'])->toFormattedDateString()}}
                </h5>
            </div>
        </div>
        <section class="p-20">
            <div class="row">
                <img src="{{$data['qrCode']}}" alt="QR Code" style="width: 350px; height: 350px; object-fit: contain;" />
            </div>
        </section>
        <section class="p-20">
            <div class="row">


                <?php

                $seat_no_arr = [];
                $seat_obj_arr = [];

                $has_sections = false;

                if(!$data['free_seating']) {
                    foreach ($data['packages'] as $package) {
                        if (!$package['package']['free_seating']) {
                            foreach ($package['seat_nos'] as $seat) {
                                array_push($seat_no_arr, $seat);
                            }
                        }
                    }
                }                

                foreach ($seat_no_arr as $seat) {
                    $seatSections = explode("_", $seat);

                    if (sizeof($seatSections) > 1) {
                        $has_sections = true;

                        $section = "";

                        if ($seatSections[0] === "BO") {
                            $section = "Balcony One";
                        } else if ($seatSections[0] === "BT") {
                            $section = "Balcony Two";
                        }

                        $seat_obj['seat_no'] = $seatSections[2];
                        $seat_obj['section'] = $section;
                        $seat_obj['sub_section'] = $seatSections[1];

                        array_push($seat_obj_arr, $seat_obj);
                    }
                }

                ?>
                <div class="col-12">
                    <table class="table-bordered">

                        <tr>
                            <th class="attendance-cell col-25">Category</th>
                            <th class="attendance-cell col-25">Price</th>
                            <th class="attendance-cell col-25">Qty</th>
                            @if(!$has_sections)
                            <th class="attendance-cell col-25">Seat No(s)</th>
                            @endif
                            <!--<th class="attendance-cell col-25">Amount (LKR)</th>-->
                        </tr>

                        @foreach($data['packages'] as $package)
                        <tr>
                            <td class="attendance-cell col-25">
                                {{$package['package']['name']}}
                            </td>
                            <td class="attendance-cell col-25">
                                {{number_format($package['package']['price'],2)}}
                            </td>
                            <td class="attendance-cell col-25">
                                {{$package['ticket_count']}}
                            </td>
                            @if(!$package['package']['free_seating'] && !$data['free_seating'])
                            <td class="attendance-cell col-25">
                                {{implode(', ', $package['seat_nos'])}}
                            </td>
                            @else
                            <td class="attendance-cell col-25">
                                {{$package['package']['desc']}}
                            </td>
                            @endif
                            <!--<td class="attendance-cell col-25">
                                {{number_format($package['package']['price'] * $package['ticket_count'],2)}}
                            </td>-->
                        </tr>
                        @endforeach
                        <!--<tr>
                            <td class="attendance-cell" colspan="3">Total Paid (LKR)</td>
                            <td class="attendance-cell" colspan="1">{{number_format($data['tot_amount'], 2)}}</td>
                        </tr>-->
                    </table>
                </div>

                @if($has_sections)
                <div class="col-12">
                    <table class="table-bordered">

                        <tr>
                            <th class="attendance-cell col-25">Seat No</th>
                            <th class="attendance-cell col-25">Section</th>
                            <th class="attendance-cell col-25">Sub Section</th>
                        </tr>

                        @foreach($seat_obj_arr as $seat_obj)
                        <tr>
                            <td class="attendance-cell col-25">
                                {{$seat_obj['seat_no']}}
                            </td>
                            <td class="attendance-cell col-25">
                                {{$seat_obj['section']}}
                            </td>
                            <td class="attendance-cell col-25">
                                {{$seat_obj['sub_section']}}
                            </td>d>
                        </tr>
                        @endforeach
                    </table>
                </div>
                @endif

            </div>
            <div class="row direction-col space-between mt-20">
                <div>(Payment Ref : {{$data['payment_ref_no']}})</div>
                <div>Booking date and time: {{$data['transaction_date_time']}}</div>
                <div>Payment Method: DIGITAL: {{$data['tot_amount']}} {{$data['currency']}}</div>
            </div>
    </div>
    </section>
    <div class="row p-20">
        <div class="col-12">
            <h5 class="m-0 red">THIS IS NOT YOUR TICKET</h5>
            <p class="m-0">Exchange this at the gate for your ticket(s)</p>
        </div>
    </div>
    <footer class="footer footer-light p-10">
        <p class="clearfix mb-0">
            <span class="float-md-start d-block d-md-inline-block mt-25">COPYRIGHT &copy;
                <script>
                    document.write(new Date().getFullYear());
                </script>
                <a class="ms-25" href="https://spotseeker.lk" target="_blank">spotseeker.lk</a>,
                <span class="d-none d-sm-inline-block">All rights Reserved</span>
            </span>
            <span class="float-md-end d-none d-md-block"><a href="https://spotseeker.lk/privacy-policy">Privacy Policy</a>
                |
                <a href="https://spotseeker.lk/terms-and-conditions">Terms and Conditions</a></span>
        </p>
    </footer>
    </div>
</body>

</html>