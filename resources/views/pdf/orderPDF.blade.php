<?php

use Carbon\Carbon;

$seat_no_arr = [];
$seat_obj_arr = [];

$has_sections = false;

if (!$data['free_seating']) {
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
<!-- We've used Millimeter sizing to get precise sizing when generating the PDF.-->
<!-- If sizes needed in px then we can simply multiply mm values by 2.83501684 -->
<html style="padding: 0; margin: 0">

<head>
  <title>{{$data['order_id']}} E TICKET</title>
</head>

<body
  style="
      padding: 0;
      margin: 0;
      width: 210mm;
      min-height: 297mm;
      height: 297mm;
      font-family: Arial, Helvetica, sans-serif;
    ">
  <table
    style="width: 210mm; height: 100%; border-collapse: collapse; padding: 0">
    <tr
      style="
          background-image: linear-gradient(
            to bottom right,
            #192145,
            #200e16
          ); /* If this is not supported, then it will fallback to background-color */
          background-color: #192145;
          color: #ffffff;
          padding: 0;
        ">
      <td
        style="
            padding-top: 2.82mm;
            padding-bottom: 2.82mm;
            padding-left: 21.14mm;
            padding-right: 21.14mm;
          ">
        <h1
          style="
              text-align: center;
              font-weight: 600;
              font-size: 8.46555818mm;
              margin: 0;
              margin-bottom: 7.05mm;
              line-height: 10.7935867mm;
            ">
          {{$data['event_name']}}
        </h1>
        <h2
          style="
              background-color: #e50914;
              border-radius: 2.83mm; /* Not sure about the support */
              text-align: center;
              padding-top: 4.24mm;
              padding-bottom: 4.24mm;
              font-weight: 700;
              font-size: 4.93824227mm;
              line-height: 6.2962589mm;
              margin: 0;
              margin-bottom: 8.46555818mm;
            ">

          @if($data['invitation'])
          INVITATION
          @else
          YOUR KEY TO THE EVENT OF YOUR DREAMS!
          @endif
        </h2>
        <table style="margin-bottom: 8.46555818mm; border-collapse: collapse">
          <tr>
            <td>
              <img
                src="data:image/jpeg;base64,{{ base64_encode(@file_get_contents(url($data['event_banner']))) }}"
                alt="Event Image"
                style="
                    width: 63.5mm;
                    height: 63.5mm;
                    border-radius: 2.82185273mm; /* Not sure about the support */
                    object-fit: cover; /* This will apply if only supported */
                  " />
            </td>
            <td style="width: 40.5mm">&nbsp;</td>
            <td>
              <img
                src="{{ $data['qrCode'] }}"
                alt="QR code"
                style="
                    width: 63.5mm;
                    height: 63.5mm;
                    border-radius: 2.82185273mm; /* Not sure about the support */
                    object-fit: cover; /* This will apply if only supported */
                  " />
            </td>
          </tr>
        </table>
        <table style="width: 100%; margin-bottom: 11.2874109mm">
          <tr style="color: #bfbfbf">
            <td
              style="
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              Event Date & Time
            </td>
            <td
              style="
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              Event Location
            </td>
            <td
              style="
                  text-align: right;
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              Booking ID
            </td>
          </tr>
          <tr style="color: #ffffff">
            <td
              style="
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              {{Carbon::parse($data['event_date'])->format('Y-m-d g:i A')}}
            </td>
            <td
              style="
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              {{$data['event_venue']}}
            </td>
            <td
              style="
                  text-align: right;
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              {{$data['order_id']}}
            </td>
          </tr>
        </table>

        @if(!$data['invitation'])
        <table
          style="
              width: 100%;
              color: #ffffff;
              border-collapse: collapse;
              margin-bottom: 5.64370545mm;
            ">
          <thead>
            <tr>
              <th
                style="
                    background-color: #262626;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;
                    text-align: left;
                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    width: 10%;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                Qty
              </th>
              <th
                style="
                    background-color: #262626;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;
                    text-align: left;
                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    width: 45%;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                Category
              </th>
              <th
                style="
                    background-color: #262626;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;
                    text-align: left;
                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    width: 25%;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                Price
              </th>
              <th
                style="
                    background-color: #262626;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;
                    text-align: left;
                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                Seat No(s)
              </th>
            </tr>
          </thead>
          <tbody>

            <?php

            $seat_no_arr = [];
            $seat_obj_arr = [];

            $has_sections = false;

            if (!$data['free_seating']) {
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

            @foreach($data['packages'] as $package)
            <tr>
              <td
                style="
                    background-color: #141414;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;

                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                {{$package['ticket_count']}}
              </td>
              <td
                style="
                    background-color: #141414;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;

                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                {{$package['package']['name']}}
              </td>
              <td
                style="
                    background-color: #141414;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;

                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                {{number_format($package['package']['price'] * $package['ticket_count'],2)}}
              </td>
              <td
                style="
                    background-color: #141414;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;

                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                @if(!$package['package']['free_seating'] && !$data['free_seating'])
                {{implode(', ', $package['seat_nos'])}}
                @else
                {{$package['package']['desc']}}
                @endif
              </td>
            </tr>
            @endforeach
          </tbody>
          </tbody>
        </table>
        @else
        <table
          style="
              width: 100%;
              color: #ffffff;
              border-collapse: collapse;
              margin-bottom: 5.64370545mm;
            ">
          <thead>
            <tr>
              <th
                style="
                    background-color: #262626;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;
                    text-align: left;
                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    width: 25%;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                Invitee Name
              </th>
              <th
                style="
                    background-color: #262626;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;
                    text-align: left;
                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    width: 50%;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                Category
              </th>
              <th
                style="
                    background-color: #262626;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;
                    text-align: left;
                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    width: 25%;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                Qty
              </th>
              
            </tr>
          </thead>
          <tbody>
          <tr>
              <td
                style="
                    background-color: #141414;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;

                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                {{$data['cust_name']}}
              </td>
              <td
                style="
                    background-color: #141414;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;

                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                {{$data['packages'][0]->package->name}}
              </td>
              <td
                style="
                    background-color: #141414;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;

                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                {{$data['tot_ticket_count']}}
              </td>
            </tr>
          </tbody>
          </tbody>
        </table>
        @endif

        @if(count($data['addons']) > 0)
        <table
          style="
              width: 100%;
              color: #ffffff;
              border-collapse: collapse;
              margin-top: 5.64370545mm;
              margin-bottom: 5.64370545mm;
            ">
          <thead>
            <tr>
              <th
                style="
                    background-color: #262626;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;
                    text-align: left;
                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    width: 10%;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                Qty
              </th>
              <th
                style="
                    background-color: #262626;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;
                    text-align: left;
                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    width: 45%;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                Addon
              </th>
              <th
                style="
                    background-color: #262626;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;
                    text-align: left;
                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                Price
              </th>
            </tr>
          </thead>
          <tbody>

            @foreach($data['addons'] as $addon)
            <tr>
              <td
                style="
                    background-color: #141414;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;

                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                {{$addon['quantity']}}
              </td>
              <td
                style="
                    background-color: #141414;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;

                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                {{$addon['addon']['name']}}
              </td>
              <td
                style="
                    background-color: #141414;
                    padding-top: 2.82185273mm;
                    padding-bottom: 2.82185273mm;
                    padding-left: 4.23277909mm;
                    padding-right: 4.23277909mm;

                    font-size: 3.52731591mm;
                    font-weight: 400;
                    line-height: 4.49732778mm;
                    border: 0.352731591mm solid #434343;
                    margin-right: -0.352731591mm;
                    margin-bottom: -0.352731591mm;
                  ">
                {{number_format($addon['addon']['price'] * $addon['quantity'],2)}}
              </td>

            </tr>
            @endforeach
          </tbody>
          </tbody>
        </table>
        @endif

        @if(!$data['invitation'])
        <table style="width: 100%; border-collapse: collapse">
          <tr style="color: #bfbfbf">
            <td
              style="
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              Payment Ref
            </td>
            <td
              style="
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              Booking Date & Time
            </td>
            <td
              style="
                  text-align: right;
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              Payment Method
            </td>
          </tr>
          <tr style="color: #ffffff">
            <td
              style="
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              {{$data['payment_ref_no']}}
            </td>
            <td
              style="
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              {{$data['transaction_date_time']}}
            </td>
            <td
              style="
                  text-align: right;
                  font-size: 3.52731591mm;
                  line-height: 4.49732778mm;
                  font-weight: 400;
                ">
              DIGITAL {{number_format($data['tot_amount'],2)}} {{$data['currency']}}
            </td>
          </tr>
        </table>
        @endif
      </td>
    </tr>


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

    <tr
      style="
          background-color: #4d000e;
          color: #ffffff;
          text-align: center;
          padding: 0;
        ">
      <td
        style="
            padding-left: 21.14mm;
            padding-right: 21.14mm;
            padding-top: 2.82mm;
            padding-bottom: 2.82mm;

            font-size: 3.52731591mm;
            line-height: 4.49732778mm;
            font-weight: 400;
          ">
        <b style="font-weight: 600; margin-right: 1.41092636mm">THIS IS ONLY AN E-TICKET:</b>exchange it at the gate for your physical ticket(s) or tag(s).
      </td>
    </tr>
    <tr style="background-color: #000000; color: #ffffff; padding: 0">
      <td
        style="
            padding-top: 8.82mm;
            padding-bottom: 2.82mm;
            padding-left: 21.14mm;
            padding-right: 21.14mm;
            font-size: 3.8800475mm;
            font-weight: 400;
            line-height: 4.94882422mm;
          ">
        <b style="font-weight: 600; margin-bottom: 1.41092636mm">Event Terms & Conditions</b>
        <ul
          style="
              color: #bfbfbf;
              margin: 0;
              margin-bottom: 8.46555818mm;
              padding-left: 7.05463182mm;
              font-weight: 400;
            ">
          <li>
            All participants must follow the rules set by SpotSeeker.lk (Pvt)
            Ltd, the event organizer, and venue. Non-compliance may lead to
            removal without a refund.
          </li>
          <li>
            Participants should act respectfully and professionally.
            Inappropriate behavior or legal violations may result in immediate
            removal without a refund.
          </li>
          <li>
            SpotSeeker.lk (Pvt) Ltd reserves the right to deny entry or remove
            participants at its discretion and seek compensation for any
            damages caused by their actions.
          </li>
          <li>
            Participants are responsible for their own insurance and must
            ensure they have valid travel documents such as visas, IDs, or
            passports.
          </li>
        </ul>
        <table style="margin-left: auto; margin-right: auto">
          <tr>
            <td>
              <img
                src="data:image/jpeg;base64,{{ base64_encode(@file_get_contents(url("https://spotseeker.s3.ap-south-1.amazonaws.com/public/logo-trans.png"))) }}"
                alt="Logo"
                style="width: 30.7mm; height: 14.14mm" />
            </td>
            <td style="color: #bfbfbf; padding-left: 7.05mm">
              <p style="margin: 0; margin-bottom: 1.41092636mm">
                Copyrights <script>
                  document.write(new Date().getFullYear());
                </script> spotseeker.lk
              </p>
              <a href="https://spotseeker.lk/privacy" style="color: inherit; text-decoration: none">
                Privacy Policy
              </a>
              |
              <a href="https://spotseeker.lk/terms" style="color: inherit; text-decoration: none">
                Terms & Conditions
              </a>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>

</html>