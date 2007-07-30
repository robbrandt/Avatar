<?php
/**
 * Avatar Module
 * 
 * The Avatar module allows uploading of individual Avatars.
 * It is based on EnvoAvatar from A.T.Web, http://www.atw.it
 * 
 * @package    Avatar
 * @version    $Id$
 * @author     Joerg Napp 
 * @link       http://lottasophie.sf.net
 * @copyright  Copyright (C) 2004
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License
 */

/**
 * Avatar_user_main()
 * 
 * Main function, shows the avatars to select from and the upload form.
 * 
 * @return output The main module page
 */
function Avatar_user_main()
{ 
    // only logged-ins might see the overview.
    if (!pnUserLoggedIn()) {
        return LogUtil::registerError(_AVATAR_ERR_NOTLOGGEDIN, null, pnModURL('Avatar'));
    } 
    
    // plus, the user should have overview right to see the avatars.
    if (!SecurityUtil::checkPermission('Avatar::', '::', ACCESS_OVERVIEW)) {
        return LogUtil::registerPermissionError('index.php');
    }
    
    // is the user allowed to upload an Avatar?
    $allow_uploads = SecurityUtil::checkPermission('Avatar::', '::', ACCESS_COMMENT); 
    
    // get all possible avatars
    $avatars = pnModAPIFunc('Avatar', 'user', 'getAvatars'); 

    // display
    $pnRender = pnRender::getInstance('Avatar', false);
    $pnRender->add_core_data();

    $pnRender->assign('avatars', $avatars);
    $pnRender->assign('allow_uploads', $allow_uploads);
    $pnRender->assign('user_avatar', pnUserGetVar('user_avatar'));
    return $pnRender->fetch('Avatar_user_main.htm');
} 

/**
 * Avatar_user_upload()
 * 
 * This is the upload function. 
 * It takes the uploaded file, performs the relevant checks to see if
 * the file meets the upload policy, and sets the uploaded file as the
 * new avatar of the user.
 */
function Avatar_user_upload ($args)
{ 

    // permission check
    if (!SecurityUtil::checkPermission('Avatar::', '::', ACCESS_COMMENT)) {
        return LogUtil::registerPermissionError('index.php');
    }

    if (!SecurityUtil::confirmAuthKey()) {
        return LogUtil::registerAuthidError('index.php');
    }
    
    // get the file
    $uploadfile = $_FILES['filelocale'];
    
    if (!is_uploaded_file($_FILES['filelocale']['tmp_name'])) {
        return LogUtil::registerError(_AVATAR_ERR_FILEUPLOAD, null, pnModURL('Avatar'));
    } 

    $tmp_file = tempnam(pnConfigGetVar('temp'), 'Avatar');
    move_uploaded_file($_FILES['filelocale']['tmp_name'], $tmp_file);
    
    $allow_resize = pnModGetVar('Avatar', 'allow_resize');
    
    // check for file size limit
    if (!$allow_resize && filesize($tmp_file) > pnModGetVar('Avatar', 'maxsize')) {
        unlink($tmp_file);
        return LogUtil::registerError(_AVATAR_ERR_FILESIZE, null, pnModURL('Avatar'));
    } 
    
    // Get image information
    $imageinfo = getimagesize($tmp_file); 

    // file is not an image
    if (!$imageinfo) {
        unlink($tmp_file);
        return LogUtil::registerError(_AVATAR_ERR_FILETYPE, null, pnModURL('Avatar'));
    } 

    $extension = image_type_to_extension($imageinfo[2], false); 
    // check for image type
    $allowed_extensions = explode (';', pnModGetVar('Avatar', 'allowed_extensions'));
    if (!in_array($extension, $allowed_extensions)) {
        unlink($tmp_file);
        return LogUtil::registerError(_AVATAR_ERR_FILETYPE, null, pnModURL('Avatar'));
    } 
    
    
    // check for image dimensions limit
    $maxwidth = pnModGetVar('Avatar', 'maxwidth');
    $maxheight = pnModGetVar('Avatar', 'maxheight');
    if ($imageinfo[0] > $maxwidth || $imageinfo[1] > $maxheight) {
        if (!$allow_resize) {
            unlink($tmp_file);
            return LogUtil::registerError(_AVATAR_ERR_FILEDIMENSIONS, null, pnModURL('Avatar'));
        } else {
            // resize the image
            
            // get the new dimensions
            $width = $imageinfo[0];
            $height = $imageinfo[1];
            
            if ($width > $maxwidth) {
                $height = ($maxwidth / $width) * $height;
                $width = $maxwidth;
            }

            if ($height > $maxheight) {
                $width = ($maxheight / $height) * $width;
                $height = $maxheight;
            }

            // get the correct functions based on the image type
            switch ($imageinfo[2]) {
                case 1:
                    $createfunc = 'imagecreatefromgif';
                    $savefunc = 'imagegif';
                    break;
                case 2:
                    $createfunc = 'ImageCreateFromJpeg';
                    $savefunc = 'imagejpeg';
                    break;
                case 3:
                    $createfunc = 'imagecreatefrompng';
                    $savefunc = 'imagepng';
                    break;
                case 4:
                    $createfunc = 'imagecreatefromwbmp';
                    $savefunc = 'imagewbmp';
                    break;
            } 
        
            $srcImage = $createfunc($tmp_file);
            $destImage = imagecreatetruecolor($width, $height);
            imagecopyresampled($destImage, $srcImage, 0, 0, 0, 0, $width, $height, $imageinfo[0], $imageinfo[1]);
            $savefunc($destImage, '/foo.jpg');
            $savefunc($destImage, $tmp_file);

            // free the memory
            imagedestroy($srcImage);
            imagedestroy($destImage);
        }
    } 
    
    // everything's OK, so upload
    $pathavatar = pnModGetVar('Avatar', 'avatardir');
    $pathphpbb = pnModGetVar('Avatar', 'forumdir');

    $uid = pnUserGetVar('uid');
    $user_avatar = "pers_$uid.$extension";

    // delete old user avatars
    foreach (glob("$pathavatar/pers_$uid.*") as $file) {
        unlink($file);
    }

    if (!@copy($tmp_file, "$pathavatar/$user_avatar")) {
        unlink($tmp_file);
        return LogUtil::registerError(_AVATAR_ERR_COPYAVATAR, null, pnModURL('Avatar'));
    } else {
        chmod ("$pathavatar/$user_avatar", 0644);
    }
    if (pnModAvailable('pnPHPbb') && $pathphpbb != '') {
        unlink("$pathphpbb/$user_avatar");
        if (!@copy($tmp_file, "$pathphpbb/$user_avatar")) {
            unlink($tmp_file);
            return LogUtil::registerError(_AVATAR_ERR_COPYFORUM, null, pnModURL('Avatar'));
        } else {
            chmod ("$pathavatar/$user_avatar", 0644);
        }
    } 
    unlink($tmp_file);

    if (!pnModAPIFunc('Avatar', 'user', 'SetAvatar',
                      array('uid'    => $uid,
                            'avatar' => $user_avatar))) {
        return LogUtil::registerError(_AVATAR_ERR_SELECT, null, pnModURL('Avatar'));
    } 
    return pnRedirect(pnModURL('Avatar', 'user', 'main'));
} 


/**
 * Avatar_user_SetAvatar()
 * 
 * Takes the new avatar from the input space and selects it as 
 * the new avatar. 
 * 
 * @param $args
 * @return 
 **/
function Avatar_user_SetAvatar($args)
{
    // only logged-ins might see the overview.
    if (!pnUserLoggedIn()) {
        return LogUtil::registerError(_AVATAR_ERR_NOTLOGGEDIN, null, pnModURL('Avatar'));
    } 
    
    // plus, the user should have overview right to see the avatars.
    if (!SecurityUtil::checkPermission('Avatar::', '::', ACCESS_OVERVIEW)) {
        return LogUtil::registerPermissionError('index.php');
    }
    
    $user_avatar = FormUtil::getPassedValue('user_avatar', '', 'GETPOST'); 
    
    pnModAPIFunc('Avatar', 'user', 'setAvatar',
                       array('uid'    => pnUserGetVar('uid'),
                             'avatar' => $user_avatar));

    return pnRedirect(pnModURL('Avatar'));
} 


if (!function_exists('image_type_to_extension')) {
    /**
     * image_type_to_extension()
     * 
     * returns the correct extension for a given image type as returned by getimagesize
     * 
     * @param integer $imagetype the image type, returned by getimagesize()
     * @param boolean $include_dot prepend a dot to the extension
     * @return string the file extension
     */
    function image_type_to_extension($imagetype, $include_dot = true)
    {
        if (empty($imagetype)) return false;
        $dot = $include_dot ? '.' : '';
        switch ($imagetype) {
            case IMAGETYPE_GIF     : return $dot . 'gif';
            case IMAGETYPE_JPEG    : return $dot . 'jpg';
            case IMAGETYPE_PNG     : return $dot . 'png';
            case IMAGETYPE_SWF     : return $dot . 'swf';
            case IMAGETYPE_PSD     : return $dot . 'psd';
            case IMAGETYPE_BMP     : return $dot . 'bmp';
            case IMAGETYPE_TIFF_II : return $dot . 'tiff';
            case IMAGETYPE_TIFF_MM : return $dot . 'tiff';
            case IMAGETYPE_JPC     : return $dot . 'jpc';
            case IMAGETYPE_JP2     : return $dot . 'jp2';
            case IMAGETYPE_JPX     : return $dot . 'jpf';
            case IMAGETYPE_JB2     : return $dot . 'jb2';
            case IMAGETYPE_SWC     : return $dot . 'swc';
            case IMAGETYPE_IFF     : return $dot . 'aiff';
            case IMAGETYPE_WBMP    : return $dot . 'wbmp';
            case IMAGETYPE_XBM     : return $dot . 'xbm';
            default                : return false;
        } 
    } 
} 

?>